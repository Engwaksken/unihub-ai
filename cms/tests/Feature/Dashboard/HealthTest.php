<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    private const ALERT = '6f1d8a8e-1111-4c4c-9a9a-0000000005a1';

    private function current(array $overrides = []): array
    {
        return array_merge([
            'sampled_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'stale' => false, 'host_id' => 'vps-1',
            'cpu_pct' => 37.5, 'ram_used_pct' => 71.2, 'ram_total_mb' => 3900, 'ram_used_mb' => 2777, 'disk_used_pct' => 40.0,
            'queue_depth' => 2, 'running' => 1, 'worker_heartbeat_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'ollama_status' => 'loaded', 'loaded_model' => 'qwen2.5:1.5b', 'tls_expires_at' => now()->addDays(40)->utc()->format('Y-m-d\TH:i:s\Z'),
            'swap_total_mb' => 2048, 'swap_used_mb' => 512, 'swap_used_pct' => 25.0,
            'services' => [['name' => 'ollama', 'status' => 'active'], ['name' => 'mariadb', 'status' => 'failed']],
        ], $overrides);
    }

    private function fakeGateway(array $current, array $alerts = [], string $admission = 'open'): void
    {
        Http::fake([
            self::GATEWAY.'/health*' => Http::response(['data' => ['current' => $current, 'workers' => [
                ['worker_id' => 'w-1', 'pid' => 1234, 'busy' => true, 'started_at' => '2026-11-26T06:00:00Z', 'heartbeat_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')],
            ], 'history' => [
                ['sampled_at' => '2026-11-26T08:00:00Z', 'cpu_pct' => 20, 'ram_used_pct' => 60, 'disk_used_pct' => 40, 'queue_depth' => 0],
                ['sampled_at' => '2026-11-26T08:00:30Z', 'cpu_pct' => null, 'ram_used_pct' => 61, 'disk_used_pct' => 40, 'queue_depth' => 1],
            ]]]),
            self::GATEWAY.'/alerts/*/ack' => Http::response(['data' => ['id' => self::ALERT, 'type' => 'ram_high', 'status' => 'acknowledged']]),
            self::GATEWAY.'/alerts*' => $this->page($alerts),
            self::GATEWAY.'/settings*' => Http::response(['data' => ['admission' => $admission, 'global_queue_limit' => 8]]),
            self::GATEWAY.'/summary*' => Http::response(['data' => ['admission' => $admission]]),
            self::GATEWAY.'/admission' => fn (Request $r) => Http::response(['data' => ['admission' => $r->data()['state']]]),
        ]);
    }

    private function alert(): array
    {
        return ['id' => self::ALERT, 'type' => 'ram_high', 'severity' => 'warning', 'status' => 'open', 'message' => 'RAM usage 91%',
            'first_seen_at' => '2026-11-26T08:00:00Z', 'last_seen_at' => '2026-11-26T08:30:00Z', 'acked_by' => null, 'acked_at' => null, 'resolved_at' => null];
    }

    public function test_current_health_history_workers_and_alerts(): void
    {
        $this->fakeGateway($this->current(), [$this->alert()]);

        $this->actingAs(User::factory()->opsAdmin()->create())->get('/health')->assertOk()
            ->assertSee('37.5%')->assertSee('71.2%')->assertSee('2,777 of 3,900 MB')->assertSee('OK')
            ->assertSee('Model loaded')->assertSee('qwen2.5:1.5b is in memory')->assertSee('days left')
            ->assertSee('w-1')->assertSee('Running a job')
            ->assertSee('ram_high')->assertSee('RAM usage 91%')->assertSee('Acknowledge')
            ->assertSee('data-chart=', false)->assertSee('Pause admission');
    }

    public function test_stale_sample_is_labelled_stale_not_current(): void
    {
        $this->fakeGateway($this->current(['stale' => true, 'sampled_at' => '2026-11-26T08:00:00Z', 'worker_heartbeat_at' => '2026-11-26T08:00:00Z']));

        $html = $this->actingAs(User::factory()->owner()->create())->get('/health')->assertOk()->getContent();
        $this->assertStringContainsString('Stale', $html);
        $this->assertStringContainsString('Last known 37.5%', $html);
        $this->assertStringContainsString('Missing', $html); // heartbeat older than 60 s
    }

    public function test_no_sample_renders_unknown_never_zero(): void
    {
        $nulls = array_map(fn () => null, $this->current());
        $nulls['stale'] = true;
        $nulls['ollama_status'] = 'unknown';
        $this->fakeGateway($nulls);

        $html = $this->actingAs(User::factory()->owner()->create())->get('/health')->assertOk()->getContent();
        $this->assertStringContainsString('No telemetry sample has been received', $html);
        $this->assertGreaterThanOrEqual(6, substr_count($html, 'Unknown'));
        $this->assertDoesNotMatchRegularExpression('#metric-value">\s*0(\.0)?%#', $html);
    }

    public function test_swap_and_service_status_render(): void
    {
        $this->fakeGateway($this->current());

        $this->actingAs(User::factory()->opsAdmin()->create())->get('/health')->assertOk()
            ->assertSee('Swap used')->assertSee('25.0%')->assertSee('512 of 2,048 MB')
            ->assertSee('<code>ollama</code>', false)->assertSee('Active')
            ->assertSee('<code>mariadb</code>', false)->assertSee('Failed')
            ->assertSee('Swap %');
    }

    public function test_host_without_swap_and_without_service_list(): void
    {
        $this->fakeGateway($this->current(['swap_total_mb' => 0, 'swap_used_mb' => 0, 'swap_used_pct' => null, 'services' => []]));

        $this->actingAs(User::factory()->opsAdmin()->create())->get('/health')->assertOk()
            ->assertSee('No swap')->assertSee('No service status reported');
    }

    public function test_older_gateway_without_swap_fields_shows_unknown(): void
    {
        $current = $this->current();
        unset($current['swap_total_mb'], $current['swap_used_mb'], $current['swap_used_pct'], $current['services']);
        $this->fakeGateway($current);

        $html = $this->actingAs(User::factory()->opsAdmin()->create())->get('/health')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#Swap used</dt>\s*<dd[^>]*>\s*Unknown#', $html);
    }

    public function test_sections_are_tabs_and_filters_return_to_their_tab(): void
    {
        $this->fakeGateway($this->current(), [$this->alert()]);
        $ops = User::factory()->opsAdmin()->create();

        $html = $this->actingAs($ops)->get('/health')->assertOk()->getContent();
        foreach (['current', 'services', 'history', 'workers', 'alerts'] as $tab) {
            $this->assertStringContainsString('id="health-tab-'.$tab.'"', $html);
        }
        $this->assertStringContainsString("x-data=\"{ tab: 'current'", $html);
        $this->assertStringContainsString('Services (1 down)', $html); // mariadb failed in the fixture
        $this->assertStringContainsString('<input type="hidden" name="tab" value="alerts">', $html);

        $this->assertStringContainsString("x-data=\"{ tab: 'alerts'", $this->actingAs($ops)->get('/health?tab=alerts')->getContent());
        $this->assertStringContainsString("x-data=\"{ tab: 'current'", $this->actingAs($ops)->get('/health?tab=bogus')->getContent());
    }

    public function test_auditor_sees_health_read_only(): void
    {
        $this->fakeGateway($this->current(), [$this->alert()], 'paused');
        $auditor = User::factory()->auditor([])->create();

        $this->actingAs($auditor)->get('/health')->assertOk()
            ->assertSee('Paused')->assertDontSee('Acknowledge<span', false)->assertDontSee('Resume admission');
        // Auditors read admission from the summary, not from settings (settings.view is owner/ops).
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/settings'));

        $this->actingAs($auditor)->post('/alerts/'.self::ALERT.'/ack')->assertForbidden();
        $this->actingAs($auditor)->put('/admission', ['state' => 'open', 'reason' => 'x'])->assertForbidden();
    }

    public function test_project_manager_cannot_view_health(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->projectManager([])->create())->get('/health')->assertForbidden();
    }

    public function test_acknowledge_alert(): void
    {
        $this->fakeGateway($this->current(), [$this->alert()]);

        $this->actingAs(User::factory()->opsAdmin()->create())->from('/health')->post('/alerts/'.self::ALERT.'/ack')->assertRedirect('/health');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/alerts/'.self::ALERT.'/ack') && $r->hasHeader('Idempotency-Key'));
    }

    public function test_admission_pause_needs_reason_and_confirmation(): void
    {
        $this->fakeGateway($this->current());
        $owner = User::factory()->owner()->create();

        $html = $this->actingAs($owner)->get('/health')->getContent();
        $this->assertMatchesRegularExpression('#<dialog[^>]*>\s*<form method="POST" action="[^"]+/admission">#', $html);

        $this->actingAs($owner)->put('/admission', ['state' => 'paused', 'reason' => ''])->assertSessionHasErrors('reason');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');

        $this->actingAs($owner)->put('/admission', ['state' => 'paused', 'reason' => 'Ollama upgrade'])->assertRedirect('/health')->assertSessionHas('status');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data() === ['state' => 'paused', 'reason' => 'Ollama upgrade'] && $r->hasHeader('Idempotency-Key'));
    }

    public function test_health_is_cached_for_30_seconds(): void
    {
        $this->fakeGateway($this->current());
        $ops = User::factory()->opsAdmin()->create();

        $this->actingAs($ops)->get('/health')->assertOk();
        $this->actingAs($ops)->get('/health')->assertOk();
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/health'))->count());
    }
}
