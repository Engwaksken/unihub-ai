<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OverviewTest extends TestCase
{
    use RefreshDatabase;

    private const MINE = '6f1d8a8e-1111-4c4c-9a9a-0000000001a1';

    private const OTHER = '6f1d8a8e-1111-4c4c-9a9a-0000000001a2';

    /** @param array<string, mixed> $overrides */
    private function summary(array $overrides = []): array
    {
        return array_merge([
            'generated_at' => '2026-11-26T09:00:00Z',
            'range' => ['from' => '2026-11-20T00:00:00Z', 'to' => '2026-11-26T23:59:59Z'],
            'active_projects' => 3,
            'requests_today' => 42,
            'completion_rate' => 0.95,
            'queue_depth' => 1,
            'running' => 1,
            'p95_total_ms' => 18500,
            'ram_used_pct' => 61.5,
            'telemetry_sampled_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'telemetry_stale' => false,
            'model_status' => 'loaded',
            'loaded_model' => 'qwen2.5:1.5b',
            'admission' => 'open',
            'trend' => [
                ['day' => '2026-11-25', 'requests' => 30, 'failed' => 2, 'p95_total_ms' => 17000],
                ['day' => '2026-11-26', 'requests' => 42, 'failed' => 1, 'p95_total_ms' => null],
            ],
        ], $overrides);
    }

    private function fake(array $summary): void
    {
        Http::fake([
            self::GATEWAY.'/summary*' => Http::response(['data' => $summary]),
            self::GATEWAY.'/projects*' => $this->page([
                $this->projectPayload(['id' => self::MINE, 'name' => 'Mine']),
                $this->projectPayload(['id' => self::OTHER, 'name' => 'Other']),
            ]),
        ]);
    }

    public function test_cards_trend_and_table_render(): void
    {
        $this->fake($this->summary());

        $this->actingAs(User::factory()->owner()->create())->get('/overview')->assertOk()
            ->assertSee('Active projects')->assertSee('42')
            ->assertSee('95.0%')
            ->assertSee('18.5 s')
            ->assertSee('61.5%')
            ->assertSee('Loaded')->assertSee('qwen2.5:1.5b')
            ->assertSee('Open')
            ->assertSee('data-chart=', false)
            ->assertSee('id="trend-table"', false)
            ->assertSee('2026-11-25');
    }

    public function test_stale_and_unknown_values_are_never_zero(): void
    {
        $this->fake($this->summary([
            'completion_rate' => null,
            'p95_total_ms' => null,
            'ram_used_pct' => null,
            'telemetry_stale' => true,
            'telemetry_sampled_at' => '2026-11-26T08:00:00Z',
            'model_status' => 'unknown',
            'loaded_model' => null,
        ]));

        $html = $this->actingAs(User::factory()->owner()->create())->get('/overview')->assertOk()->getContent();

        $this->assertStringContainsString('Stale', $html);
        $this->assertStringContainsString('Unknown', $html);
        $this->assertStringContainsString('No finished jobs yet today', $html);
        $this->assertStringNotContainsString('0.0%', $html);
        $this->assertStringNotContainsString('>0 ms', $html);
    }

    public function test_no_telemetry_at_all_is_unknown(): void
    {
        $this->fake($this->summary(['telemetry_sampled_at' => null, 'telemetry_stale' => true, 'ram_used_pct' => null]));

        $this->actingAs(User::factory()->owner()->create())->get('/overview')->assertOk()
            ->assertSee('no sample received yet');
    }

    public function test_dates_are_sent_as_kampala_day_boundaries_in_utc(): void
    {
        $this->fake($this->summary());

        $this->actingAs(User::factory()->owner()->create())->get('/overview?from=2026-11-20&to=2026-11-26')->assertOk();

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), '/summary')) {
                return false;
            }
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);

            return $q['from'] === '2026-11-19T21:00:00Z' && $q['to'] === '2026-11-26T20:59:59Z'
                && $q['tz'] === 'Africa/Kampala'; // "today" on the gateway uses the display zone (G11)
        });
    }

    public function test_project_manager_can_filter_own_project_but_not_others(): void
    {
        $this->fake($this->summary());
        $pm = User::factory()->projectManager([self::MINE])->create();

        $this->actingAs($pm)->get('/overview?project_id='.self::MINE)->assertOk()->assertSee('Mine');
        $this->actingAs($pm)->get('/overview?project_id='.self::OTHER)->assertNotFound();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), self::OTHER));
    }

    public function test_project_filter_options_are_scoped(): void
    {
        $this->fake($this->summary());

        $this->actingAs(User::factory()->auditor([self::MINE])->create())->get('/overview')->assertOk()
            ->assertSee('Your assigned projects')
            ->assertSee('<option value="'.self::MINE.'"', false)
            ->assertDontSee('<option value="'.self::OTHER.'"', false);
    }

    public function test_summary_is_cached_30s_per_actor_scope(): void
    {
        $this->fake($this->summary());
        $ownerA = User::factory()->owner()->create();
        $ownerB = User::factory()->owner()->create();
        $pm = User::factory()->projectManager([self::MINE])->create();

        $this->actingAs($ownerA)->get('/overview')->assertOk();
        $this->actingAs($ownerB)->get('/overview')->assertOk(); // same scope: cache hit
        $this->actingAs($pm)->get('/overview')->assertOk();     // different scope: new call

        $summaryCalls = fn () => collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/summary'))->count();
        $this->assertSame(2, $summaryCalls());

        $this->travel(31)->seconds();
        $this->actingAs($ownerA)->get('/overview')->assertOk();
        $this->assertSame(3, $summaryCalls());
    }

    public function test_gateway_down_shows_unreachable_state(): void
    {
        Http::fake([self::GATEWAY.'/*' => Http::failedConnection()]);

        $this->actingAs(User::factory()->owner()->create())->get('/overview')->assertOk()->assertSee('Gateway unreachable');
    }

    public function test_home_goes_to_overview(): void
    {
        $this->actingAs(User::factory()->auditor([])->create())->get('/')->assertRedirect('/overview');
    }
}
