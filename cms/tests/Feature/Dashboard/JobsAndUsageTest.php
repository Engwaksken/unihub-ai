<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JobsAndUsageTest extends TestCase
{
    use RefreshDatabase;

    private const A = '6f1d8a8e-1111-4c4c-9a9a-0000000003a1';

    private const B = '6f1d8a8e-1111-4c4c-9a9a-0000000003b2';

    private const JOB = '6f1d8a8e-1111-4c4c-9a9a-0000000003c3';

    private function job(array $overrides = []): array
    {
        return array_merge([
            'id' => self::JOB, 'project_id' => self::A, 'task' => 'daily_plan', 'task_version' => 2, 'model' => 'qwen2.5:1.5b',
            'state' => 'queued', 'attempts' => 0, 'max_tokens' => 200, 'tokens_in' => null, 'tokens_out' => null,
            'error_code' => null, 'retry_of' => null, 'request_id' => null,
            'queued_at' => '2026-11-26T09:00:00Z', 'started_at' => null, 'finished_at' => null,
            'queue_ms' => null, 'inference_ms' => null, 'expires_at' => null, 'created_at' => '2026-11-26T09:00:00Z',
        ], $overrides);
    }

    private function fakeJobs(array $jobs): void
    {
        Http::fake([
            self::GATEWAY.'/jobs/*/cancel' => Http::response(['data' => $this->job(['state' => 'cancelled'])]),
            self::GATEWAY.'/jobs/*/retry' => Http::response(['data' => $this->job(['id' => '6f1d8a8e-1111-4c4c-9a9a-0000000003d4', 'retry_of' => self::JOB])], 201),
            self::GATEWAY.'/jobs*' => $this->page($jobs, 'next-jobs'),
            self::GATEWAY.'/projects?*' => $this->page([
                $this->projectPayload(['id' => self::A, 'name' => 'Alpha']),
                $this->projectPayload(['id' => self::B, 'name' => 'Beta']),
            ]),
            self::GATEWAY.'/projects/*' => Http::response(['data' => $this->projectPayload(['id' => self::A, 'name' => 'Alpha'])]),
        ]);
    }

    public function test_jobs_list_shows_metadata_and_never_payloads(): void
    {
        $this->fakeJobs([
            $this->job(),
            $this->job(['id' => '6f1d8a8e-1111-4c4c-9a9a-0000000003e5', 'state' => 'failed', 'error_code' => 'OLLAMA_TIMEOUT', 'queue_ms' => 1200, 'inference_ms' => 120000, 'tokens_in' => 50, 'tokens_out' => null, 'input' => 'SECRET PROMPT', 'output' => 'SECRET OUTPUT']),
        ]);

        $this->actingAs(User::factory()->opsAdmin()->create())->get('/jobs')->assertOk()
            ->assertSee(self::JOB)->assertSee('daily_plan')->assertSee('OLLAMA_TIMEOUT')
            ->assertSee('1.2 s')->assertSee('120.0 s')->assertSee('n/a')
            ->assertSee('Alpha')
            ->assertSee('Cancel')->assertSee('Retry')->assertSee('Next page')
            ->assertDontSee('SECRET PROMPT')->assertDontSee('SECRET OUTPUT');
    }

    public function test_filters_are_sent_and_project_filter_is_scoped(): void
    {
        $this->fakeJobs([$this->job()]);
        $pm = User::factory()->projectManager([self::A])->create();

        $this->actingAs($pm)->get('/jobs?project_id='.self::A.'&state=failed&from=2026-11-01&to=2026-11-02')->assertOk();
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), '/jobs?')) {
                return false;
            }
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);

            return $q['project_id'] === self::A && $q['state'] === 'failed' && $q['from'] === '2026-10-31T21:00:00Z';
        });

        $this->actingAs($pm)->get('/jobs?project_id='.self::B)->assertNotFound();
    }

    public function test_jobs_outside_scope_are_dropped_even_if_gateway_returns_them(): void
    {
        $this->fakeJobs([$this->job(), $this->job(['id' => '6f1d8a8e-1111-4c4c-9a9a-0000000003f6', 'project_id' => self::B])]);

        $this->actingAs(User::factory()->auditor([self::A])->create())->get('/jobs')->assertOk()
            ->assertSee(self::JOB)->assertDontSee('0000000003f6')
            ->assertDontSee('Cancel job'); // auditors cannot operate
    }

    public function test_cancel_requires_job_manage_on_that_project(): void
    {
        $this->fakeJobs([$this->job()]);
        $mixed = User::factory()->withTwoFactor()->withRoles([Role::ProjectManager, Role::Auditor])
            ->withMemberships([self::A => Role::ProjectManager, self::B => Role::Auditor])->create();

        $this->actingAs($mixed)->post('/jobs/'.self::JOB.'/cancel', ['project_id' => self::B])->assertForbidden();
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/cancel'));

        $this->actingAs($mixed)->from('/jobs')->post('/jobs/'.self::JOB.'/cancel', ['project_id' => self::A])->assertRedirect('/jobs');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/jobs/'.self::JOB.'/cancel') && $r->hasHeader('Idempotency-Key'));
    }

    public function test_cancel_is_inside_a_confirmation_dialog(): void
    {
        $this->fakeJobs([$this->job()]);

        $html = $this->actingAs(User::factory()->owner()->create())->get('/jobs')->getContent();
        $this->assertMatchesRegularExpression('#<dialog[^>]*>\s*<form method="POST" action="[^"]+/jobs/'.self::JOB.'/cancel"#', $html);
    }

    public function test_retry_requires_a_reason_and_surfaces_ineligibility(): void
    {
        $this->fakeJobs([$this->job(['state' => 'failed'])]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->from('/jobs')->post('/jobs/'.self::JOB.'/retry', ['project_id' => self::A, 'reason' => ''])
            ->assertSessionHasErrors('reason');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/retry'));

        $this->actingAs($owner)->from('/jobs')->post('/jobs/'.self::JOB.'/retry', ['project_id' => self::A, 'reason' => 'Ollama restarted'])
            ->assertRedirect('/jobs')->assertSessionHas('status');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/retry') && $r->data() === ['reason' => 'Ollama restarted']);
    }

    public function test_retry_conflict_becomes_form_error(): void
    {
        Http::fake([self::GATEWAY.'/jobs/*/retry' => $this->gatewayError(409, 'INVALID_STATE', 'Only failed jobs with a transient error and retained input can be retried')]);

        $this->actingAs(User::factory()->owner()->create())->from('/jobs')
            ->post('/jobs/'.self::JOB.'/retry', ['project_id' => self::A, 'reason' => 'try again'])
            ->assertSessionHasErrors(['form' => 'Only failed jobs with a transient error and retained input can be retried']);
    }

    public function test_project_jobs_tab(): void
    {
        $this->fakeJobs([$this->job()]);

        $this->actingAs(User::factory()->projectManager([self::A])->create())->get('/projects/'.self::A.'/jobs')->assertOk()
            ->assertSee(self::JOB)->assertSee('Project sections', false);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/jobs?') && str_contains($r->url(), 'project_id='.self::A));
    }

    // ------------------------------------------------------------------ usage

    private function usageRows(): array
    {
        return [
            ['key' => '2026-11-25', 'project_slug' => null, 'project_name' => null, 'requests' => 10, 'completed' => 9, 'failed' => 1, 'failure_rate' => 0.1,
                'tokens_in' => null, 'tokens_out' => null, 'p50_queue_ms' => 500, 'p95_queue_ms' => 1500, 'p50_inference_ms' => 9000, 'p95_inference_ms' => 20000,
                'p95_total_ms' => 21000, 'inference_ms_sum' => 90000, 'est_cost_usd' => 0.1234],
        ];
    }

    public function test_usage_report_renders_table_chart_and_estimate(): void
    {
        Http::fake([
            self::GATEWAY.'/usage*' => Http::response(['data' => $this->usageRows(), 'meta' => ['cost_note' => 'Estimate: hosting cost for the period allocated by share of inference time.']]),
            self::GATEWAY.'/projects*' => $this->page([]),
        ]);

        $this->actingAs(User::factory()->owner()->create())->get('/usage?group_by=day')->assertOk()
            ->assertSee('2026-11-25')->assertSee('10.0%')->assertSee('n/a')->assertSee('$0.12')
            ->assertSee('estimate')->assertSee('data-chart=', false)->assertSee('Export CSV');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/usage?') && str_contains($r->url(), 'tz=Africa%2FKampala') && str_contains($r->url(), 'group_by=day'));
    }

    public function test_csv_export_streams_gateway_csv(): void
    {
        $csv = "key,project_slug,requests\n2026-11-25,,10\n";
        Http::fake([self::GATEWAY.'/usage*' => Http::response($csv, 200, ['Content-Type' => 'text/csv'])]);

        $response = $this->actingAs(User::factory()->projectManager([self::A])->create())
            ->get('/usage/export?project_id='.self::A.'&group_by=day&from=2026-11-01&to=2026-11-30');

        $response->assertOk();
        $this->assertSame($csv, $response->streamedContent());
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));

        Http::assertSent(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return ($q['format'] ?? null) === 'csv' && ($q['project_id'] ?? null) === self::A;
        });
        $this->assertDatabaseHas('audit_events', ['action' => 'usage.exported', 'gateway_project_uuid' => self::A]);
    }

    public function test_project_manager_cannot_export_other_projects(): void
    {
        Http::fake();
        $pm = User::factory()->projectManager([self::A])->create();

        $this->actingAs($pm)->get('/usage/export?project_id='.self::B)->assertNotFound();
        $this->actingAs($pm)->get('/usage?project_id='.self::B)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_unfiltered_export_by_project_manager_relies_on_assertion_scope(): void
    {
        Http::fake([self::GATEWAY.'/usage*' => Http::response("key\n", 200, ['Content-Type' => 'text/csv'])]);
        $pm = User::factory()->projectManager([self::A])->create();

        $this->actingAs($pm)->get('/usage/export')->assertOk();

        Http::assertSent(function (Request $r) {
            $claims = $this->decodeAssertion($r->header('X-Actor-Assertion')[0])['payload'];

            return $claims['project_roles'] === [self::A => 'project_manager'] && $claims['roles'] === ['project_manager'];
        });
    }

    public function test_export_gateway_error_is_an_error_page_not_a_partial_file(): void
    {
        Http::fake([self::GATEWAY.'/usage*' => Http::failedConnection()]);

        $this->actingAs(User::factory()->owner()->create())->get('/usage/export')->assertStatus(503)->assertSee('Gateway unreachable');
    }

    public function test_project_usage_tab(): void
    {
        Http::fake([
            self::GATEWAY.'/usage*' => Http::response(['data' => $this->usageRows(), 'meta' => []]),
            self::GATEWAY.'/projects/*' => Http::response(['data' => $this->projectPayload(['id' => self::A])]),
        ]);

        $this->actingAs(User::factory()->auditor([self::A])->create())->get('/projects/'.self::A.'/usage')->assertOk()->assertSee('2026-11-25');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/usage?') && str_contains($r->url(), 'project_id='.self::A));
    }
}
