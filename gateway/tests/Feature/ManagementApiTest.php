<?php

namespace Tests\Feature;

use App\Management\Capabilities;
use App\Models\AiModel;
use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\HealthSample;
use App\Models\Job;
use App\Models\Organization;
use App\Models\TaskVersion;
use App\Services\ServiceProbe;
use App\Services\Telemetry;
use App\Support\Base32;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManagementApiTest extends TestCase
{
    // ---------- authentication ----------

    public function test_requires_service_token_and_valid_assertion(): void
    {
        $this->getJson('/internal/v1/projects')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer uhm_wrong', 'X-Actor-Assertion' => $this->assertion()])
            ->getJson('/internal/v1/projects')->assertStatus(401);
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => 'Bearer '.$this->mgmtToken])->getJson('/internal/v1/projects')->assertStatus(401);
        $this->mgmt('GET', '/projects')->assertOk();
    }

    #[DataProvider('badAssertions')]
    public function test_rejects_bad_assertions(array $override): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.$this->mgmtToken, 'X-Actor-Assertion' => $this->assertion(['owner'], [], $override)])
            ->getJson('/internal/v1/projects')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public static function badAssertions(): array
    {
        $now = time();

        return [
            'expired' => [['iat' => $now - 120, 'exp' => $now - 60]],
            'ttl too long' => [['iat' => $now, 'exp' => $now + 3600]],
            'wrong audience' => [['aud' => 'something-else']],
            'wrong issuer' => [['iss' => 'evil']],
            'no mfa' => [['mfa' => false]],
            'unknown role' => [['roles' => ['superuser']]],
            'future iat' => [['iat' => $now + 600, 'exp' => $now + 620]],
        ];
    }

    public function test_rejects_replayed_and_forged_assertions(): void
    {
        $a = $this->assertion();
        $h = ['Authorization' => 'Bearer '.$this->mgmtToken, 'X-Actor-Assertion' => $a];
        $this->withHeaders($h)->getJson('/internal/v1/projects')->assertOk();
        $this->withHeaders($h)->getJson('/internal/v1/projects')->assertStatus(401); // replay

        [$hd, $pl] = explode('.', $this->assertion());
        $forged = $hd.'.'.$pl.'.'.Base32::base64url(sodium_crypto_sign_detached($hd.'.'.$pl, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
        $this->withHeaders(['X-Actor-Assertion' => $forged])->getJson('/internal/v1/projects')->assertStatus(401);
    }

    public function test_mutations_require_idempotency_key_and_replay_safely(): void
    {
        $this->mgmt('POST', '/organizations', ['name' => 'BOQ Ltd'], headers: ['Idempotency-Key' => ''])->assertStatus(422);

        $first = $this->mgmt('POST', '/organizations', ['name' => 'BOQ Ltd'], headers: ['Idempotency-Key' => 'idem-org-0001'])->assertStatus(201);
        $replay = $this->mgmt('POST', '/organizations', ['name' => 'BOQ Ltd'], headers: ['Idempotency-Key' => 'idem-org-0001'])->assertStatus(201);
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, Organization::where('name', 'BOQ Ltd')->count());

        $this->mgmt('POST', '/organizations', ['name' => 'Other'], headers: ['Idempotency-Key' => 'idem-org-0001'])
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
    }

    // ---------- organizations & projects ----------

    public function test_owner_creates_organization_and_project_and_it_is_audited(): void
    {
        $this->task('daily_plan');
        $org = $this->mgmt('POST', '/organizations', ['name' => 'Unihub Solutions Uganda'])->assertStatus(201)->json('data');

        $p = $this->mgmt('POST', '/projects', [
            'organization_id' => $org['id'], 'slug' => 'my-digital-diary', 'name' => 'My Digital Diary',
            'environment' => 'staging', 'allowed_tasks' => ['daily_plan'], 'contact_email' => 'it@example.org',
        ])->assertStatus(201)->json('data');

        $this->assertSame(['daily_plan'], $p['allowed_tasks']);
        $this->assertSame(100, $p['daily_limit']);
        $this->assertSame(5, $p['rpm_limit']);
        $this->assertSame(2, $p['queue_limit']);
        $this->assertSame($org['id'], $p['organization']['id']);

        $this->mgmt('POST', '/projects', ['organization_id' => $org['id'], 'slug' => 'my-digital-diary', 'name' => 'Dup', 'environment' => 'staging'])
            ->assertStatus(422)->assertJsonPath('error.details.fields.slug.0', fn ($m) => is_string($m));
        $this->mgmt('POST', '/projects', ['organization_id' => $org['id'], 'slug' => 'x2-proj', 'name' => 'X', 'environment' => 'staging', 'allowed_tasks' => ['nope']])
            ->assertStatus(422);

        $audit = AuditEvent::where('action', 'project.created')->first();
        $this->assertSame('cms:user-owner', $audit->actor_ref);
        $this->assertSame($p['id'], $audit->project_id);
        $this->assertNotNull($audit->request_id);
    }

    public function test_project_manager_is_scoped_to_assigned_projects(): void
    {
        $mine = $this->project(['name' => 'Mine']);
        $other = $this->project(['name' => 'Other']);
        $pm = ['project_manager'];

        $list = $this->mgmt('GET', '/projects', [], $pm, [$mine->id])->assertOk()->json('data');
        $this->assertSame([$mine->id], array_column($list, 'id'));

        $this->mgmt('GET', "/projects/{$other->id}", [], $pm, [$mine->id])->assertStatus(404);
        $this->mgmt('PATCH', "/projects/{$other->id}", ['name' => 'Hacked'], $pm, [$mine->id])->assertStatus(404);
        $this->mgmt('GET', "/projects/{$other->id}/keys", [], $pm, [$mine->id])->assertStatus(404);
        $this->mgmt('POST', "/projects/{$other->id}/keys", [], $pm, [$mine->id])->assertStatus(404);
        $this->mgmt('GET', '/jobs?project_id='.$other->id, [], $pm, [$mine->id])->assertOk()->assertJsonCount(0, 'data');
        $this->mgmt('GET', '/usage?project_id='.$other->id, [], $pm, [$mine->id])->assertStatus(404);

        $orgs = $this->mgmt('GET', '/organizations', [], $pm, [$mine->id])->assertOk()->json('data');
        $this->assertCount(1, $orgs);
    }

    public function test_per_project_roles_do_not_leak_across_projects(): void
    {
        // PM on A, only auditor on B (CMS contract finding #1).
        $a = $this->project();
        $b = $this->project();
        $roles = ['project_manager', 'auditor'];
        $map = [$a->id => 'project_manager', $b->id => 'auditor'];

        $this->mgmt('PATCH', "/projects/{$a->id}", ['name' => 'A renamed'], $roles, $map)->assertOk();
        $this->mgmt('PATCH', "/projects/{$b->id}", ['name' => 'B renamed'], $roles, $map)->assertStatus(403);
        $this->mgmt('POST', "/projects/{$a->id}/keys", [], $roles, $map)->assertStatus(201);
        $this->mgmt('POST', "/projects/{$b->id}/keys", [], $roles, $map)->assertStatus(403);
        $this->mgmt('GET', "/projects/{$b->id}/keys", [], $roles, $map)->assertOk(); // auditor may view
        $this->assertCount(2, $this->mgmt('GET', '/projects', [], $roles, $map)->json('data'));
    }

    public function test_project_roles_claim_is_required_and_validated(): void
    {
        $p = $this->project();
        $h = fn (array $o) => ['Authorization' => 'Bearer '.$this->mgmtToken, 'X-Actor-Assertion' => $this->assertion(['project_manager'], [$p->id], $o)];
        $this->withHeaders($h(['project_roles' => null]))->getJson('/internal/v1/projects')->assertStatus(401);
        $this->withHeaders($h(['project_roles' => [$p->id]]))->getJson('/internal/v1/projects')->assertStatus(401); // list, not map
        $this->withHeaders($h(['project_roles' => [$p->id => 'owner']]))->getJson('/internal/v1/projects')->assertStatus(401); // global role per project
        $this->withHeaders($h(['project_roles' => [$p->id => 'auditor']]))->getJson('/internal/v1/projects')->assertStatus(401); // role not held
    }

    public function test_project_manager_cannot_raise_own_quota_or_change_status(): void
    {
        $p = $this->project();
        $pm = ['project_manager'];
        $this->mgmt('PATCH', "/projects/{$p->id}", ['daily_limit' => 10000], $pm, [$p->id])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->mgmt('PATCH', "/projects/{$p->id}", ['status' => 'disabled'], $pm, [$p->id])->assertStatus(403);
        $this->mgmt('PATCH', "/projects/{$p->id}", ['allowed_tasks' => []], $pm, [$p->id])->assertStatus(403);
        $this->mgmt('PATCH', "/projects/{$p->id}", ['name' => 'Renamed'], $pm, [$p->id])->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->mgmt('POST', '/projects', ['organization_id' => $p->organization_id, 'slug' => 'new-one', 'name' => 'N', 'environment' => 'staging'], $pm, [$p->id])->assertStatus(403);
    }

    public function test_owner_changes_quota_and_status_with_audit_diff(): void
    {
        $p = $this->project();
        $this->mgmt('PATCH', "/projects/{$p->id}", ['daily_limit' => 250, 'rpm_limit' => 8])->assertOk()->assertJsonPath('data.daily_limit', 250);
        $e = AuditEvent::where('action', 'project.updated')->first();
        $this->assertSame(['from' => 100, 'to' => 250], $e->metadata['daily_limit']);

        $this->mgmt('PATCH', "/projects/{$p->id}", ['status' => 'archived'])->assertOk();
        $this->mgmt('PATCH', "/projects/{$p->id}", ['name' => 'Valid name'])->assertStatus(409);
        $this->mgmt('PATCH', "/projects/{$p->id}", ['slug' => 'changed'])->assertStatus(422);
        $this->assertTrue(AuditEvent::where('action', 'project.status_changed')->exists());
    }

    public function test_auditor_is_read_only(): void
    {
        $p = $this->project();
        $aud = ['auditor'];
        $this->mgmt('GET', "/projects/{$p->id}", [], $aud, [$p->id])->assertOk();
        $this->mgmt('GET', "/projects/{$p->id}/keys", [], $aud, [$p->id])->assertOk();
        $this->mgmt('PATCH', "/projects/{$p->id}", ['name' => 'x'], $aud, [$p->id])->assertStatus(403);
        $this->mgmt('POST', "/projects/{$p->id}/keys", [], $aud, [$p->id])->assertStatus(403);
        $this->mgmt('POST', '/organizations', ['name' => 'x'], $aud, [$p->id])->assertStatus(403);
        $this->mgmt('PATCH', '/settings', ['default_rpm_limit' => 9], $aud)->assertStatus(403);
    }

    public function test_ops_admin_cannot_issue_keys_or_change_quotas(): void
    {
        $p = $this->project();
        $ops = ['ops_admin'];
        $this->mgmt('POST', "/projects/{$p->id}/keys", [], $ops)->assertStatus(403);
        $this->mgmt('PATCH', "/projects/{$p->id}", ['daily_limit' => 1], $ops)->assertStatus(403);
        $this->mgmt('PATCH', "/projects/{$p->id}", ['status' => 'disabled'], $ops)->assertOk();
    }

    // ---------- keys ----------

    public function test_key_lifecycle_issue_reveal_once_rotate_revoke(): void
    {
        $p = $this->project(['queue_limit' => 5]);
        $issued = $this->mgmt('POST', "/projects/{$p->id}/keys", ['label' => 'backend'], headers: ['Idempotency-Key' => 'issue-key-0001'])
            ->assertStatus(201)->assertHeader('Cache-Control');
        $secret = $issued->json('secret');
        $this->assertMatchesRegularExpression('/^uhk_test_[0-9a-z]{10}_[A-Za-z0-9_-]{43}$/', $secret);
        $this->assertStringStartsWith('no-store', $issued->headers->get('Cache-Control'));

        // Replaying issuance never reveals the secret again.
        $this->mgmt('POST', "/projects/{$p->id}/keys", ['label' => 'backend'], headers: ['Idempotency-Key' => 'issue-key-0001'])
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_REPLAY_UNAVAILABLE');

        $listing = $this->mgmt('GET', "/projects/{$p->id}/keys")->assertOk();
        $this->assertStringNotContainsString(substr($secret, -43), $listing->getContent());
        $this->assertArrayNotHasKey('digest', $listing->json('data.0'));
        $this->assertStringNotContainsString(substr($secret, -43), json_encode(AuditEvent::all()));

        $this->submit($secret)->assertStatus(202);

        // Rotate: new key, old key keeps working until overlap end.
        $old = ApiKey::where('project_id', $p->id)->first();
        $rot = $this->mgmt('POST', "/projects/{$p->id}/keys", ['rotates_key_id' => $old->id, 'overlap_days' => 3])->assertStatus(201);
        $this->assertNotNull($old->fresh()->expires_at);
        $this->assertTrue($old->fresh()->expires_at->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));
        $this->submit($secret)->assertStatus(202);
        $this->submit($rot->json('secret'))->assertStatus(202);

        $this->mgmt('POST', "/keys/{$old->id}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->submit($secret)->assertStatus(401);
        $this->assertSame(1, AuditEvent::where('action', 'key.revoked')->count());
        $this->mgmt('POST', "/keys/{$old->id}/revoke")->assertOk(); // idempotent
        $this->assertSame(1, AuditEvent::where('action', 'key.revoked')->count());
    }

    // ---------- models & tasks ----------

    public function test_model_refresh_and_enable_rules(): void
    {
        Http::fake(['127.0.0.1:11434/api/tags' => Http::response(['models' => [
            ['name' => 'qwen2.5:1.5b', 'digest' => 'sha256:abc', 'size' => 986000000],
            ['name' => 'llama3.2:1b', 'digest' => 'sha256:def', 'size' => 1300000000],
        ]])]);
        $this->mgmt('POST', '/models/refresh')->assertOk()->assertJsonCount(2, 'data');
        $qwen = AiModel::where('tag', 'qwen2.5:1.5b')->first();
        $llama = AiModel::where('tag', 'llama3.2:1b')->first();
        $this->assertFalse($qwen->enabled);

        $this->mgmt('PATCH', "/models/{$qwen->id}", ['enabled' => true])->assertStatus(422); // licence required
        $this->mgmt('PATCH', "/models/{$qwen->id}", ['enabled' => true, 'licence' => 'Apache-2.0'])->assertOk();
        $this->mgmt('PATCH', "/models/{$llama->id}", ['enabled' => true, 'licence' => 'Llama 3.2 Community'])->assertStatus(409);
        $this->mgmt('PATCH', "/models/{$qwen->id}", ['enabled' => true], ['project_manager'])->assertStatus(403);
    }

    public function test_task_versions_are_immutable_once_published(): void
    {
        $model = $this->model();
        $this->mgmt('POST', '/tasks', ['key' => 'daily_plan', 'system_template' => 'You plan days concisely.', 'output_cap' => 200, 'model_id' => $model->id])
            ->assertStatus(201)->assertJsonPath('data.current_version', null);
        $this->mgmt('POST', '/tasks/daily_plan/versions/1/publish')->assertOk()->assertJsonPath('data.current_version', 1);
        $this->mgmt('POST', '/tasks/daily_plan/versions/1/publish')->assertStatus(409);
        $this->mgmt('POST', '/tasks', ['key' => 'daily_plan', 'system_template' => 'Version two template.', 'output_cap' => 150, 'model_id' => $model->id])
            ->assertStatus(201)->assertJsonCount(2, 'data.versions');

        $v1 = TaskVersion::where('version', 1)->first();
        $this->expectException(\LogicException::class);
        $v1->update(['output_cap' => 10]);
    }

    // ---------- jobs ----------

    public function test_job_listing_has_no_payloads_and_retry_rules(): void
    {
        [$p, $key] = $this->projectWithKey();
        $id = $this->submit($key, ['input' => 'SECRET-PROMPT-TEXT'])->json('job_id');
        $r = $this->mgmt('GET', '/jobs?project_id='.$p->id)->assertOk();
        $this->assertStringNotContainsString('SECRET-PROMPT-TEXT', $r->getContent());
        $this->assertSame($id, $r->json('data.0.id'));

        $this->mgmt('POST', "/jobs/{$id}/retry", ['reason' => 'ops retry'])->assertStatus(409); // queued, not failed

        $ollamaUp = false;
        Http::fake(function () use (&$ollamaUp) {
            return $ollamaUp ? Http::response(['message' => ['content' => 'ok']]) : Http::response(['error' => 'x'], 500);
        });
        Artisan::call('gateway:work', ['--stop-when-empty' => true]);
        $this->assertSame('failed', Job::find($id)->state);

        // G11: one job's metadata, with error_transient so the CMS only offers Retry when it can work.
        $one = $this->mgmt('GET', "/jobs/{$id}", [], ['auditor'], [$p->id])->assertOk()->json('data');
        $this->assertSame('failed', $one['state']);
        $this->assertTrue($one['error_transient']);
        $this->assertStringNotContainsString('SECRET-PROMPT-TEXT', json_encode($one));
        $other = $this->project();
        $this->mgmt('GET', "/jobs/{$id}", [], ['auditor'], [$other->id])->assertStatus(404); // out of scope: existence not revealed
        $this->mgmt('GET', '/jobs/'.Str::uuid7())->assertStatus(404);
        $this->mgmt('GET', '/jobs/not-a-uuid')->assertStatus(404);

        $ollamaUp = true;
        $new = $this->mgmt('POST', "/jobs/{$id}/retry", ['reason' => 'Ollama restarted'])->assertStatus(201)->json('data');
        $this->assertSame($id, $new['retry_of']);
        $this->assertNull($new['error_transient']); // no error yet
        $this->mgmt('POST', "/jobs/{$id}/retry", ['reason' => 'again'])->assertStatus(409);
        Artisan::call('gateway:work', ['--stop-when-empty' => true]);
        $this->assertSame('completed', Job::find($new['id'])->state);
        $this->assertTrue(AuditEvent::where('action', 'job.retried')->exists());
    }

    public function test_cancel_queued_job_via_management(): void
    {
        [$p, $key] = $this->projectWithKey();
        $id = $this->submit($key)->json('job_id');
        $this->mgmt('POST', "/jobs/{$id}/cancel", [], ['project_manager'], [$p->id])->assertOk()->assertJsonPath('data.state', 'cancelled');
        $this->mgmt('POST', "/jobs/{$id}/cancel", [], ['auditor'], [$p->id])->assertStatus(403);
    }

    // ---------- reports, health, alerts ----------

    public function test_summary_and_usage_with_scoped_csv_export(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'ok'], 'prompt_eval_count' => 10, 'eval_count' => 5])]);
        [$a, $keyA] = $this->projectWithKey(['slug' => '=cmd-proj']);
        [$b, $keyB] = $this->projectWithKey();
        $this->submit($keyA);
        $this->submit($keyB);
        Artisan::call('gateway:work', ['--stop-when-empty' => true]);

        $s = $this->mgmt('GET', '/summary')->assertOk()->json('data');
        $this->assertSame(2, $s['requests_today']);
        $this->assertSame(1.0, (float) $s['completion_rate']);
        $this->assertSame('unknown', $s['model_status']); // no telemetry yet → unknown, not 0
        $this->assertTrue($s['telemetry_stale']);
        $this->assertNull($s['ram_used_pct']);

        $pmSummary = $this->mgmt('GET', '/summary', [], ['project_manager'], [$a->id])->json('data');
        $this->assertSame(1, $pmSummary['requests_today']);

        $usage = $this->mgmt('GET', '/usage?group_by=project')->assertOk()->json('data');
        $this->assertCount(2, $usage);
        $this->assertSame(10, $usage[0]['tokens_in']);
        $this->assertNotNull($usage[0]['est_cost_usd']);

        $csv = $this->mgmt('GET', '/usage?group_by=project&format=csv', [], ['project_manager'], [$a->id])->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString("'=cmd-proj", $content); // formula injection neutralised
        $this->assertSame(2, substr_count(trim($content), "\n") + 1); // header + 1 scoped row
        $this->assertTrue(AuditEvent::where('action', 'usage.exported')->exists());
    }

    public function test_telemetry_alerts_open_ack_and_resolve(): void
    {
        Http::fake(['*/api/ps' => Http::response(['models' => [['name' => 'qwen2.5:1.5b']]])]);
        $t = app(Telemetry::class);
        $sample = HealthSample::create(['host_id' => 'h', 'sampled_at' => now(), 'ram_used_pct' => 91.0, 'disk_used_pct' => 40.0,
            'queue_depth' => 0, 'running' => 0, 'worker_heartbeat_at' => now(), 'ollama_status' => 'loaded']);
        $t->evaluate($sample->fresh());

        $alerts = $this->mgmt('GET', '/alerts?status=open')->assertOk()->json('data');
        $this->assertSame(['ram_high'], array_column($alerts, 'type'));
        $this->mgmt('POST', '/alerts/'.$alerts[0]['id'].'/ack', [], ['auditor'])->assertStatus(403);
        $this->mgmt('POST', '/alerts/'.$alerts[0]['id'].'/ack')->assertOk()->assertJsonPath('data.status', 'acknowledged');

        $ok = HealthSample::create(['host_id' => 'h', 'sampled_at' => now(), 'ram_used_pct' => 50.0, 'queue_depth' => 0, 'running' => 0,
            'worker_heartbeat_at' => now(), 'ollama_status' => 'loaded']);
        $t->evaluate($ok->fresh());
        $this->mgmt('GET', '/alerts?status=resolved')->assertJsonPath('data.0.type', 'ram_high');

        $health = $this->mgmt('GET', '/health')->assertOk()->json('data.current');
        $this->assertFalse($health['stale']);
        $this->assertNull($health['cpu_pct']); // not measured → unknown
    }

    public function test_usage_filters_by_organization_key_and_model_and_groups_by_period(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-27T10:00:00Z'));
        Http::fake(['*' => Http::response(['message' => ['content' => 'ok'], 'prompt_eval_count' => 10, 'eval_count' => 5])]);
        $otherOrg = Organization::create(['name' => 'BOQ Ltd', 'status' => 'active']);
        [$a, $keyA] = $this->projectWithKey(['name' => 'Alpha']);
        [$b, $keyB] = $this->projectWithKey(['name' => 'Beta', 'organization_id' => $otherOrg->id]);
        $this->submit($keyA);
        $this->submit($keyA);
        $this->submit($keyB);
        Artisan::call('gateway:work', ['--stop-when-empty' => true]);
        $keyAId = ApiKey::where('project_id', $a->id)->value('id');
        $range = 'from=2026-11-01T00:00:00Z&to=2026-11-28T00:00:00Z';

        $byOrg = $this->mgmt('GET', "/usage?{$range}&group_by=organization")->assertOk()->json('data');
        $this->assertSame(['BOQ Ltd', 'Unihub Solutions'], array_column($byOrg, 'label'));
        $this->assertSame([1, 2], array_column($byOrg, 'requests'));

        $org = $this->mgmt('GET', "/usage?{$range}&group_by=project&organization_id={$otherOrg->id}")->json('data');
        $this->assertSame(['Beta'], array_column($org, 'label'));

        $byKey = $this->mgmt('GET', "/usage?{$range}&group_by=key&api_key_id={$keyAId}")->assertOk()->json('data');
        $this->assertCount(1, $byKey);
        $this->assertSame($keyAId, $byKey[0]['key']);
        $this->assertSame('Key ('.ApiKey::find($keyAId)->prefix.')', $byKey[0]['label']); // unlabelled key: prefix only
        $this->assertSame(2, $byKey[0]['requests']);

        $this->assertSame(3, $this->mgmt('GET', "/usage?{$range}&group_by=model&model=qwen2.5:1.5b")->json('data.0.requests'));
        $this->assertSame([], $this->mgmt('GET', "/usage?{$range}&model=no-such-model")->json('data'));

        $this->assertSame('2026-W48', $this->mgmt('GET', "/usage?{$range}&group_by=week")->json('data.0.key'));
        $this->assertSame('2026-11', $this->mgmt('GET', "/usage?{$range}&group_by=month")->json('data.0.key'));

        // Filters only narrow scope: a PM of Alpha filtering by Beta's org sees nothing, not Beta.
        $this->assertSame([], $this->mgmt('GET', "/usage?{$range}&organization_id={$otherOrg->id}", [], ['project_manager'], [$a->id])->json('data'));
        $this->mgmt('GET', "/usage?{$range}&api_key_id=nope")->assertStatus(422);
        $this->mgmt('GET', "/usage?{$range}&group_by=hour")->assertStatus(422);

        $csv = $this->mgmt('GET', "/usage?{$range}&group_by=key&api_key_id={$keyAId}&format=csv")->assertOk()->streamedContent();
        $this->assertStringStartsWith('key,label,', $csv);
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1); // header + the one key
        $exported = AuditEvent::where('action', 'usage.exported')->firstOrFail()->toApi();
        $this->assertSame($keyAId, $exported['metadata']['api_key_id']); // the export's filters are audited
    }

    public function test_summary_today_and_trend_follow_tz(): void
    {
        // 22:30 UTC on 26 Nov is 01:30 on 27 Nov in Nairobi (UTC+3).
        $this->travelTo(CarbonImmutable::parse('2026-11-26T22:30:00Z'));
        Http::fake(['*' => Http::response(['message' => ['content' => 'ok'], 'prompt_eval_count' => 1, 'eval_count' => 1])]);
        [, $key] = $this->projectWithKey();
        $this->travelTo(CarbonImmutable::parse('2026-11-26T20:00:00Z')); // 23:00 Nairobi, 26 Nov
        $this->submit($key);
        Artisan::call('gateway:work', ['--stop-when-empty' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-11-26T22:30:00Z'));

        $utc = $this->mgmt('GET', '/summary?from=2026-11-20T00:00:00Z')->assertOk()->json('data');
        $this->assertSame('UTC', $utc['tz']);
        $this->assertSame(1, $utc['requests_today']);
        $this->assertSame('2026-11-26', $utc['trend'][0]['day']);

        $eat = $this->mgmt('GET', '/summary?from=2026-11-20T00:00:00Z&tz=Africa/Nairobi')->assertOk()->json('data');
        $this->assertSame('Africa/Nairobi', $eat['tz']);
        $this->assertSame(0, $eat['requests_today']); // it is already the 27th in Nairobi
        $this->assertSame('2026-11-26', $eat['trend'][0]['day']);

        $this->mgmt('GET', '/summary?tz=Mars/Olympus')->assertStatus(422);
    }

    public function test_swap_and_service_status_sampled_and_alerted(): void
    {
        $info = "MemTotal:  4000000 kB\nMemFree: 100 kB\nMemAvailable:  1000000 kB\nSwapCached: 0 kB\nSwapTotal:  2097152 kB\nSwapFree:  1048576 kB\n";
        $this->assertSame(['total' => 3906, 'used' => 2930, 'swap_total' => 2048, 'swap_used' => 1024], Telemetry::parseMeminfo($info));
        $noSwap = Telemetry::parseMeminfo("MemTotal: 2048 kB\nMemAvailable: 1024 kB\n");
        $this->assertNull($noSwap['swap_total']);
        $this->assertNull(Telemetry::parseMeminfo('garbage'));

        $sample = HealthSample::create(['host_id' => 'h', 'sampled_at' => now(), 'swap_total_mb' => 2048, 'swap_used_mb' => 1536,
            'queue_depth' => 0, 'running' => 0, 'worker_heartbeat_at' => now(), 'ollama_status' => 'loaded',
            'services' => [['name' => 'ollama', 'status' => 'active'], ['name' => 'mariadb', 'status' => 'failed'], ['name' => 'x', 'status' => 'unknown']]]);
        app(Telemetry::class)->evaluate($sample->fresh());

        $open = collect($this->mgmt('GET', '/alerts?status=open')->json('data'))->keyBy('type');
        $this->assertSame('Swap usage 75%', $open['swap_high']['message']);
        $this->assertSame('critical', $open['service_down']['severity']);
        $this->assertSame('Service not running: mariadb', $open['service_down']['message']); // "unknown" is not an outage

        $current = $this->mgmt('GET', '/health')->assertOk()->json('data.current');
        $this->assertEquals(75.0, $current['swap_used_pct']);
        $this->assertSame(2048, $current['swap_total_mb']);
        $this->assertSame('failed', $current['services'][1]['status']);

        // A host without swap is "no swap", not 0 % or 100 %.
        $none = HealthSample::create(['host_id' => 'h', 'sampled_at' => now(), 'swap_total_mb' => 0, 'swap_used_mb' => 0,
            'queue_depth' => 0, 'running' => 0, 'worker_heartbeat_at' => now(), 'ollama_status' => 'loaded', 'services' => []]);
        $this->assertNull(Telemetry::swapPct($none));
        app(Telemetry::class)->evaluate($none->fresh());
        $types = array_column($this->mgmt('GET', '/alerts?status=resolved')->json('data'), 'type');
        $this->assertContains('swap_high', $types);
        $this->assertContains('service_down', $types);
    }

    public function test_service_probe_rejects_unsafe_unit_names(): void
    {
        $probe = new ServiceProbe;
        $this->assertSame('unknown', $probe->status('ollama; rm -rf /'));
        $this->assertSame('unknown', $probe->status('--help'));
        config(['gateway.monitored_services' => ['bad name']]);
        $this->assertSame([['name' => 'bad name', 'status' => 'unknown']], $probe->statuses());
    }

    public function test_heartbeat_missing_alert_when_no_worker(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        app(Telemetry::class)->sample();
        $types = array_column($this->mgmt('GET', '/alerts?status=open')->json('data'), 'type');
        $this->assertContains('worker_heartbeat_missing', $types);
        $this->assertContains('ollama_unreachable', $types);
    }

    // ---------- settings, admission, audit ----------

    public function test_settings_validated_owner_only_and_audited(): void
    {
        $this->mgmt('PATCH', '/settings', ['default_rpm_limit' => 7], ['ops_admin'])->assertStatus(403);
        $this->mgmt('PATCH', '/settings', ['default_rpm_limit' => 7])->assertOk()->assertJsonPath('data.default_rpm_limit', 7);
        $this->mgmt('PATCH', '/settings', ['approved_providers' => ['https://api.example.com']])->assertStatus(422);
        $this->mgmt('PATCH', '/settings', ['admission' => 'paused'])->assertStatus(422); // only via /admission
        $this->mgmt('PATCH', '/settings', ['bogus' => 1])->assertStatus(422);
        $e = AuditEvent::where('action', 'settings.updated')->first();
        $this->assertSame(['from' => 5, 'to' => 7], $e->metadata['default_rpm_limit']);
    }

    public function test_admission_pause_blocks_submissions(): void
    {
        [, $key] = $this->projectWithKey();
        $this->mgmt('PUT', '/admission', ['state' => 'paused', 'reason' => 'incident drill'], ['ops_admin'])->assertOk();
        $this->submit($key)->assertStatus(503);
        $this->mgmt('PUT', '/admission', ['state' => 'open', 'reason' => 'resolved'], ['ops_admin'])->assertOk();
        $this->submit($key)->assertStatus(202);
        $this->assertSame(2, AuditEvent::whereIn('action', ['admission.paused', 'admission.resumed'])->count());
    }

    public function test_audit_is_append_only_and_scoped(): void
    {
        $mine = $this->project();
        $other = $this->project();
        $this->mgmt('PATCH', "/projects/{$mine->id}", ['name' => 'Mine renamed']);
        $this->mgmt('PATCH', "/projects/{$other->id}", ['name' => 'Other renamed']);

        $events = $this->mgmt('GET', '/audit', [], ['auditor'], [$mine->id])->assertOk()->json('data');
        $this->assertSame([$mine->id], array_values(array_unique(array_column($events, 'project_id'))));

        $e = AuditEvent::first();
        try {
            $e->update(['action' => 'tampered']);
            $this->fail('audit update should be blocked');
        } catch (QueryException $ex) {
            $this->assertStringContainsString('append-only', $ex->getMessage());
        }
        $this->expectException(QueryException::class);
        $e->delete();
    }

    public function test_capability_matrix_matches_permissions_doc(): void
    {
        $doc = file_get_contents(base_path('../docs/permissions.md'));
        $this->assertStringContainsString('cannot raise own', $doc);
        $m = Capabilities::MATRIX;
        $this->assertSame(['owner' => 'all', 'project_manager' => 'assigned'], $m['key.manage']);
        $this->assertSame(['owner' => 'all'], $m['project.quota']);
        $this->assertArrayNotHasKey('auditor', $m['job.operate']);
        $this->assertArrayNotHasKey('project_manager', $m['health.view']);
        foreach ($m as $cap => $grants) {
            $this->assertSame([], array_diff(array_keys($grants), Capabilities::ROLES), $cap);
        }
        $this->assertNotEmpty(Str::uuid()); // keeps import used
    }
}
