<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Create forms in modals, statistics cards, and search/filter on list pages. */
class ModalsAndStatsTest extends TestCase
{
    use RefreshDatabase;

    private const ORG = '6f1d8a8e-1111-4c4c-9a9a-0000000000a1';

    private const A = '6f1d8a8e-1111-4c4c-9a9a-00000000000a';

    private const B = '6f1d8a8e-1111-4c4c-9a9a-00000000000b';

    private function metric(string $html, string $label, int $value): void
    {
        $this->assertMatchesRegularExpression('#'.preg_quote($label, '#').'</dt>\s*<dd class="metric-value">\s*'.$value.'\b#', $html, $label);
    }

    public function test_projects_page_has_stats_and_create_modal(): void
    {
        Http::fake([
            self::GATEWAY.'/projects*' => $this->page([
                $this->projectPayload(['id' => self::A, 'name' => 'Diary', 'status' => 'active', 'environment' => 'production']),
                $this->projectPayload(['id' => self::B, 'name' => 'Library', 'status' => 'disabled']),
            ]),
            self::GATEWAY.'/organizations*' => $this->page([
                $this->organizationPayload(['id' => self::ORG, 'name' => 'WITU', 'status' => 'active']),
                $this->organizationPayload(['id' => '6f1d8a8e-1111-4c4c-9a9a-0000000000a2', 'name' => 'Gone Ltd', 'status' => 'archived']),
            ]),
        ]);

        $html = $this->actingAs(User::factory()->owner()->create())->get('/projects')->assertOk()->getContent();

        $this->metric($html, 'Projects', 2);
        $this->metric($html, 'Active', 1);
        $this->metric($html, 'Disabled', 1);
        $this->assertStringContainsString('1 in production', $html);
        $this->assertMatchesRegularExpression('#<dialog[^>]*>.*?action="[^"]+/projects"#s', $html);
        $this->assertStringContainsString('name="_modal" value="create-project"', $html);
        // Only active organizations can receive a new project.
        $this->assertMatchesRegularExpression('#<select id="organization_id"[^>]*>(?:(?!</select>).)*WITU#s', $html);
        $this->assertDoesNotMatchRegularExpression('#<select id="organization_id"[^>]*>(?:(?!</select>).)*Gone Ltd#s', $html);
    }

    public function test_auditor_sees_stats_for_assigned_projects_only_and_no_create_button(): void
    {
        Http::fake([
            self::GATEWAY.'/projects*' => $this->page([
                $this->projectPayload(['id' => self::A, 'status' => 'active']),
                $this->projectPayload(['id' => self::B, 'status' => 'active']),
            ]),
        ]);

        $html = $this->actingAs(User::factory()->auditor([self::A])->create())->get('/projects')->assertOk()->getContent();
        $this->metric($html, 'Projects', 1);
        $this->assertStringNotContainsString('create-project', $html);
    }

    public function test_organizations_create_is_a_modal_and_stats_count_everything(): void
    {
        Http::fake([self::GATEWAY.'/organizations*' => $this->page([
            $this->organizationPayload(['name' => 'WITU', 'status' => 'active', 'project_count' => 3]),
            $this->organizationPayload(['id' => '6f1d8a8e-1111-4c4c-9a9a-0000000000a2', 'name' => 'Old', 'status' => 'archived', 'project_count' => 1]),
        ])]);
        $owner = User::factory()->owner()->create();

        $html = $this->actingAs($owner)->get('/organizations')->assertOk()->getContent();
        $this->metric($html, 'Organizations', 2);
        $this->metric($html, 'Archived', 1);
        $this->metric($html, 'Projects', 4);
        $this->assertMatchesRegularExpression('#<dialog[^>]*>.*?action="[^"]+/organizations"#s', $html);

        // A validation error re-opens the modal.
        $this->actingAs($owner)->from('/organizations')->post('/organizations', ['_modal' => 'create-organization', 'name' => ''])
            ->assertRedirect('/organizations');
        $this->assertStringContainsString('showModal())"', $this->actingAs($owner)->get('/organizations')->getContent());
    }

    public function test_task_versions_are_created_from_a_modal_prefilled_per_task(): void
    {
        Http::fake([
            self::GATEWAY.'/models*' => $this->page([['id' => '6f1d8a8e-1111-4c4c-9a9a-0000000004a1', 'tag' => 'qwen2.5:1.5b', 'enabled' => true]]),
            self::GATEWAY.'/tasks*' => $this->page([['id' => 't1', 'key' => 'daily_plan', 'current_version' => null, 'versions' => []]]),
        ]);

        $html = $this->actingAs(User::factory()->opsAdmin()->create())->get('/tasks')->assertOk()->getContent();
        $this->assertStringContainsString('name="_modal" value="task-version"', $html);
        $this->assertStringContainsString("\$dispatch('new-task-version', { key: 'daily_plan' })", $html);
        $this->assertStringContainsString('qwen2.5:1.5b', $html);
    }

    public function test_keys_page_filters_by_project_and_status_with_stats(): void
    {
        Http::fake([
            self::GATEWAY.'/projects/*/keys*' => $this->page([
                ['id' => 'k1', 'prefix' => 'uhk_live_aa', 'label' => 'backend', 'status' => 'active', 'expires_at' => now()->addDays(3)->toIso8601String(), 'last_used_at' => null],
                ['id' => 'k2', 'prefix' => 'uhk_live_bb', 'label' => 'old', 'status' => 'revoked', 'expires_at' => null, 'last_used_at' => '2026-11-01T00:00:00Z'],
            ]),
            self::GATEWAY.'/projects*' => $this->page([$this->projectPayload(['id' => self::A, 'name' => 'Diary'])]),
        ]);
        $owner = User::factory()->owner()->create();

        $html = $this->actingAs($owner)->get('/keys')->assertOk()->getContent();
        $this->metric($html, 'Keys on this page', 2);
        $this->metric($html, 'Expiring within 14 days', 1);
        $this->metric($html, 'Active, never used', 1);

        // One table for all projects, with a project column and a manage link per row.
        $this->assertSame(1, substr_count($html, '<caption class="visually-hidden">API keys by project</caption>'));
        $this->assertSame(2, substr_count($html, '>Manage<span class="visually-hidden"> keys for Diary'));

        $this->actingAs($owner)->get('/keys?q=dia&status=revoked')->assertOk()
            ->assertSee('uhk_live_bb')->assertDontSee('uhk_live_aa');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/projects?') && str_contains($r->url(), 'q=dia'));
        $this->actingAs($owner)->get('/keys?status=nope')->assertSessionHasErrors('status');
    }
}
