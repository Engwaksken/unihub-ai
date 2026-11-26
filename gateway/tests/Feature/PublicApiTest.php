<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Job;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    public function test_healthz_is_minimal_and_unauthenticated(): void
    {
        $this->getJson('/healthz')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_submit_returns_202_and_poll_shows_queued(): void
    {
        [, $key] = $this->projectWithKey();
        $r = $this->submit($key)->assertStatus(202)->assertJsonStructure(['job_id', 'status', 'request_id']);
        $this->assertSame('queued', $r->json('status'));
        $this->assertSame($r->json('request_id'), $r->headers->get('X-Request-Id'));

        $this->withToken($key)->getJson('/v1/jobs/'.$r->json('job_id'))
            ->assertOk()
            ->assertJson(['status' => 'queued', 'task' => 'daily_plan', 'task_version' => 1, 'model' => 'qwen2.5:1.5b'])
            ->assertJsonMissingPath('output');
    }

    public function test_invalid_missing_revoked_and_expired_keys_are_rejected(): void
    {
        [$project, $key] = $this->projectWithKey();

        $this->postJson('/v1/jobs', [])->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->submit('uhk_test_0000000000_'.str_repeat('A', 43))->assertStatus(401);
        $this->submit(substr($key, 0, -1).'x')->assertStatus(401); // tampered secret, valid prefix
        $this->submit(str_replace('uhk_test_', 'uhk_live_', $key))->assertStatus(401); // env mismatch

        ApiKey::where('project_id', $project->id)->update(['expires_at' => now()->subMinute()->format('Y-m-d H:i:s.u')]);
        $this->submit($key)->assertStatus(401);

        ApiKey::where('project_id', $project->id)->update(['expires_at' => null, 'revoked_at' => now()->format('Y-m-d H:i:s.u')]);
        $this->submit($key)->assertStatus(401)->assertJsonStructure(['error' => ['code', 'message'], 'request_id']);
    }

    public function test_project_cannot_see_or_cancel_another_projects_job(): void
    {
        [, $keyA] = $this->projectWithKey();
        [, $keyB] = $this->projectWithKey();
        $jobId = $this->submit($keyA)->json('job_id');

        $this->withToken($keyB)->getJson("/v1/jobs/{$jobId}")->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->withToken($keyB)->deleteJson("/v1/jobs/{$jobId}")->assertStatus(404);
        $this->withToken($keyB)->getJson('/v1/jobs/00000000-0000-0000-0000-000000000000')->assertStatus(404);
        $this->withToken($keyB)->getJson('/v1/jobs/not-a-uuid')->assertStatus(404);
        $this->assertSame(Job::QUEUED, Job::find($jobId)->state);
    }

    public function test_project_id_in_body_is_rejected_not_trusted(): void
    {
        [, $key] = $this->projectWithKey();
        $this->submit($key, ['project_id' => 'someone-else'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_idempotent_retry_returns_same_job_without_consuming_quota(): void
    {
        [$project, $key] = $this->projectWithKey();
        $body = ['idempotency_key' => 'client-req-0001', 'input' => 'Plan my day'];

        $first = $this->submit($key, $body)->assertStatus(202)->json('job_id');
        $second = $this->submit($key, $body)->assertStatus(202)->json('job_id');
        $this->assertSame($first, $second);
        $this->assertSame(1, Job::count());
        $this->assertSame(1, (int) DB::table('quota_counters')->where('project_id', $project->id)->where('window_type', 'day')->value('count'));

        $this->submit($key, ['idempotency_key' => 'client-req-0001', 'input' => 'Something else'])
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
    }

    public function test_same_idempotency_key_in_different_projects_is_independent(): void
    {
        [, $keyA] = $this->projectWithKey();
        [, $keyB] = $this->projectWithKey();
        $a = $this->submit($keyA, ['idempotency_key' => 'shared-key-1'])->json('job_id');
        $b = $this->submit($keyB, ['idempotency_key' => 'shared-key-1'])->json('job_id');
        $this->assertNotSame($a, $b);
    }

    public function test_disallowed_task_and_disabled_project_return_403(): void
    {
        $this->task('summarise');
        [$project, $key] = $this->projectWithKey();
        $this->submit($key, ['task' => 'summarise'])->assertStatus(403)->assertJsonPath('error.code', 'TASK_NOT_ALLOWED');
        $this->submit($key, ['task' => 'unknown_task'])->assertStatus(403)->assertJsonPath('error.code', 'TASK_NOT_ALLOWED');

        $project->update(['status' => 'disabled']);
        $this->submit($key)->assertStatus(403)->assertJsonPath('error.code', 'PROJECT_DISABLED');
    }

    public function test_body_limits_content_type_and_validation(): void
    {
        [, $key] = $this->projectWithKey();

        $this->withToken($key)->call('POST', '/v1/jobs', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['task' => 'daily_plan', 'input' => str_repeat('a', 33000), 'idempotency_key' => 'big-body-1']))
            ->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');

        $this->withToken($key)->call('POST', '/v1/jobs', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'], 'hello')
            ->assertStatus(415);

        $this->withToken($key)->call('POST', '/v1/jobs', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{bad json')
            ->assertStatus(400)->assertJsonPath('error.code', 'INVALID_REQUEST');

        $this->submit($key, ['max_tokens' => 1000])->assertStatus(422)->assertJsonPath('error.details.fields.max_tokens.0', fn ($m) => is_string($m));
        $this->submit($key, ['idempotency_key' => 'short'])->assertStatus(422);
        $this->submit($key, ['input' => ''])->assertStatus(422);
    }

    public function test_token_budget_rejects_without_truncating(): void
    {
        [, $key] = $this->projectWithKey();
        // ~15 000 bytes ≈ 5 000 estimated tokens > 2 048 context
        $this->submit($key, ['input' => str_repeat('word ', 3000)])
            ->assertStatus(422)->assertJsonPath('error.code', 'TOKEN_BUDGET');
        $this->assertSame(0, Job::count());
    }

    public function test_project_queue_limit_returns_429_with_retry_after(): void
    {
        [, $key] = $this->projectWithKey(['queue_limit' => 2]);
        $this->submit($key)->assertStatus(202);
        $this->submit($key)->assertStatus(202);
        $r = $this->submit($key)->assertStatus(429)->assertJsonPath('error.code', 'QUEUE_FULL');
        $this->assertGreaterThan(0, (int) $r->headers->get('Retry-After'));
    }

    public function test_global_queue_limit_bounds_queue(): void
    {
        app(Settings::class)->set('global_queue_limit', 3, 'test');
        $keys = [];
        for ($i = 0; $i < 4; $i++) {
            [, $keys[]] = $this->projectWithKey(['queue_limit' => 2]);
        }
        $statuses = [];
        foreach ($keys as $k) {
            $statuses[] = $this->submit($k)->status();
        }
        $this->assertSame([202, 202, 202, 429], $statuses);
        $this->assertSame(3, Job::where('state', 'queued')->count());
    }

    public function test_rpm_and_daily_quota_return_429(): void
    {
        [, $key] = $this->projectWithKey(['rpm_limit' => 1, 'queue_limit' => 5]);
        $this->submit($key)->assertStatus(202);
        $this->submit($key)->assertStatus(429)->assertJsonPath('error.code', 'QUOTA_EXCEEDED')->assertHeader('Retry-After');

        [, $key2] = $this->projectWithKey(['daily_limit' => 1, 'queue_limit' => 5]);
        $this->submit($key2)->assertStatus(202);
        $this->submit($key2)->assertStatus(429)->assertJsonPath('error.code', 'QUOTA_EXCEEDED');
    }

    public function test_cancel_queued_is_idempotent_and_running_conflicts(): void
    {
        [, $key] = $this->projectWithKey();
        $id = $this->submit($key)->json('job_id');
        $this->withToken($key)->deleteJson("/v1/jobs/{$id}")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->withToken($key)->deleteJson("/v1/jobs/{$id}")->assertOk()->assertJsonPath('status', 'cancelled');

        $id2 = $this->submit($key)->json('job_id');
        Job::whereKey($id2)->update(['state' => 'running']);
        $this->withToken($key)->deleteJson("/v1/jobs/{$id2}")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATE');
    }

    public function test_cancellation_releases_queue_slot(): void
    {
        [, $key] = $this->projectWithKey(['queue_limit' => 1]);
        $id = $this->submit($key)->json('job_id');
        $this->submit($key)->assertStatus(429);
        $this->withToken($key)->deleteJson("/v1/jobs/{$id}")->assertOk();
        $this->submit($key)->assertStatus(202);
    }

    public function test_admission_paused_returns_503(): void
    {
        [, $key] = $this->projectWithKey();
        app(Settings::class)->set('admission', 'paused', 'test');
        $this->submit($key)->assertStatus(503)->assertJsonPath('error.code', 'ADMISSION_PAUSED')->assertHeader('Retry-After');
    }

    public function test_surfaces_are_isolated(): void
    {
        config(['gateway.allow_any_surface' => false]);
        [, $key] = $this->projectWithKey();

        // Public vhost cannot reach management routes, and vice versa.
        $this->withServerVariables(['GATEWAY_SURFACE' => 'public'])
            ->withHeaders(['Authorization' => 'Bearer '.$this->mgmtToken, 'X-Actor-Assertion' => $this->assertion()])
            ->getJson('/internal/v1/projects')->assertStatus(404);
        $this->withServerVariables(['GATEWAY_SURFACE' => 'internal'])->getJson('/healthz')->assertStatus(404);
        // Missing surface param (misconfigured nginx) fails closed.
        $this->withServerVariables([])->getJson('/healthz')->assertStatus(404);

        $this->withServerVariables(['GATEWAY_SURFACE' => 'public'])->getJson('/healthz')->assertOk();
        $this->withServerVariables(['GATEWAY_SURFACE' => 'public']);
        $this->submit($key)->assertStatus(202);
    }

    public function test_unknown_routes_use_error_envelope_without_internals(): void
    {
        $r = $this->getJson('/nope')->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->assertStringNotContainsString('vendor', $r->getContent());
    }
}
