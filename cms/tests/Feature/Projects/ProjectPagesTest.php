<?php

namespace Tests\Feature\Projects;

use App\Livewire\ProjectIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectPagesTest extends TestCase
{
    use RefreshDatabase;

    private const MINE = '6f1d8a8e-1111-4c4c-9a9a-0000000000d1';

    private const OTHER = '6f1d8a8e-1111-4c4c-9a9a-0000000000d2';

    private const THIRD = '6f1d8a8e-1111-4c4c-9a9a-0000000000d3';

    private function fakeProjectList(): void
    {
        Http::fake([
            self::GATEWAY.'/organizations*' => $this->page([$this->organizationPayload(['name' => 'Org A'])]),
            self::GATEWAY.'/projects*' => $this->page([
                $this->projectPayload(['id' => self::MINE, 'name' => 'Mine Project']),
                $this->projectPayload(['id' => self::OTHER, 'name' => 'Other Project']),
                $this->projectPayload(['id' => self::THIRD, 'name' => 'Third Project']),
            ], 'next-cursor-token'),
        ]);
    }

    public function test_owner_sees_all_projects(): void
    {
        $this->fakeProjectList();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ProjectIndex::class)
            ->assertSee('Mine Project')->assertSee('Other Project')->assertSee('Third Project')
            ->assertSee('New project')
            ->assertSee('Next');
    }

    public function test_project_manager_sees_only_assigned_projects_even_if_gateway_returns_more(): void
    {
        $this->fakeProjectList();
        $this->actingAs(User::factory()->projectManager([self::MINE])->create());

        Livewire::test(ProjectIndex::class)
            ->assertSee('Mine Project')
            ->assertDontSee('Other Project')
            ->assertDontSee('Third Project')
            ->assertDontSee('New project');

        // PMs cannot list organizations, so the CMS does not even ask.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/organizations'));
    }

    public function test_auditor_without_memberships_gets_empty_state_without_calling_gateway(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->auditor([])->create());

        Livewire::test(ProjectIndex::class)->assertSee('No projects assigned');
        Http::assertNothingSent();
    }

    public function test_filters_and_paging_are_sent_to_gateway(): void
    {
        $this->fakeProjectList();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ProjectIndex::class)
            ->set('search', 'diary')
            ->set('status', 'disabled')
            ->call('nextPage');

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), '/projects?')) {
                return false;
            }
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);

            return ($q['q'] ?? null) === 'diary' && ($q['status'] ?? null) === 'disabled' && ($q['cursor'] ?? null) === 'next-cursor-token';
        });
    }

    public function test_list_shows_gateway_unreachable_state(): void
    {
        Http::fake([self::GATEWAY.'/*' => Http::failedConnection()]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ProjectIndex::class)->assertSee('Gateway unreachable');
    }

    public function test_projects_page_renders_for_owner(): void
    {
        $this->fakeProjectList();
        $this->actingAs(User::factory()->owner()->create())
            ->get('/projects')->assertOk()->assertSee('Mine Project')->assertSee('Projects');
    }

    public function test_project_manager_gets_404_for_unassigned_project_without_gateway_call(): void
    {
        Http::fake();
        $pm = User::factory()->projectManager([self::MINE])->create();

        $this->actingAs($pm)->get('/projects/'.self::OTHER.'/settings')->assertNotFound();
        $this->actingAs($pm)->get('/projects/'.self::OTHER)->assertNotFound();
        $this->actingAs($pm)->patch('/projects/'.self::OTHER, ['name' => 'x'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_gateway_404_is_a_404(): void
    {
        Http::fake([self::GATEWAY.'/projects/*' => $this->gatewayError(404, 'NOT_FOUND', 'Not found')]);

        $this->actingAs(User::factory()->owner()->create())
            ->get('/projects/'.self::OTHER.'/settings')->assertNotFound();
    }

    public function test_owner_settings_page_has_every_section_enabled(): void
    {
        Http::fake([self::GATEWAY.'/projects/*' => Http::response(['data' => $this->projectPayload(['id' => self::MINE, 'name' => 'Mine Project'])])]);

        $html = $this->actingAs(User::factory()->owner()->create())
            ->get('/projects/'.self::MINE.'/settings')
            ->assertOk()
            ->assertSee('Mine Project')
            ->assertSee('Save settings')
            ->assertSee('Archive project')
            ->getContent();

        $this->assertStringNotContainsString('<fieldset class="fieldset" disabled', $html);
    }

    public function test_project_manager_settings_page_locks_quota_and_status_controls(): void
    {
        Http::fake([self::GATEWAY.'/projects/*' => Http::response(['data' => $this->projectPayload(['id' => self::MINE])])]);

        $this->actingAs(User::factory()->projectManager([self::MINE])->create())
            ->get('/projects/'.self::MINE.'/settings')
            ->assertOk()
            ->assertSee('Only an owner can change quotas')
            ->assertSee('Save settings')
            ->assertDontSee('Archive project');
    }

    public function test_project_tabs_follow_flags(): void
    {
        Http::fake([self::GATEWAY.'/projects/*' => Http::response(['data' => $this->projectPayload(['id' => self::MINE])])]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/projects/'.self::MINE.'/settings')
            ->assertSee('aria-label="Project sections"', false)
            ->assertSee('>API keys</a>', false)->assertSee('>Jobs</a>', false)->assertSee('>Usage</a>', false);

        config(['cms.features.project_keys_tab' => false, 'cms.features.project_jobs_tab' => false]);
        $this->actingAs($owner)->get('/projects/'.self::MINE.'/settings')
            ->assertDontSee('>API keys</a>', false)->assertDontSee('>Jobs</a>', false)->assertSee('>Usage</a>', false);
        $this->actingAs($owner)->get('/projects/'.self::MINE.'/keys')->assertNotFound();
    }

    public function test_project_manager_update_sends_only_changed_metadata(): void
    {
        Http::fake([self::GATEWAY.'/projects/*' => function (Request $r) {
            return Http::response(['data' => $this->projectPayload(['id' => self::MINE, 'name' => $r->method() === 'PATCH' ? 'Renamed' : 'Original'])]);
        }]);

        $this->actingAs(User::factory()->projectManager([self::MINE])->create())
            ->patch('/projects/'.self::MINE, [
                '_idempotency_key' => '11111111-2222-4333-8444-555555555555',
                'name' => 'Renamed',
                'environment' => 'staging',
                'owner_ref' => 'Pilot lead',
                'contact_email' => 'pilot@example.test',
                'daily_limit' => 99999, // not permitted for PMs: must not be sent
                'allowed_tasks' => 'everything',
            ])
            ->assertRedirect('/projects/'.self::MINE.'/settings')
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['name' => 'Renamed']
            && $r->header('Idempotency-Key')[0] === '11111111-2222-4333-8444-555555555555');
    }

    public function test_project_manager_cannot_change_status(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->projectManager([self::MINE])->create())
            ->patch('/projects/'.self::MINE.'/status', ['status' => 'archived'])
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_auditor_cannot_update_project(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->auditor([self::MINE])->create())
            ->patch('/projects/'.self::MINE, ['name' => 'x'])
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_owner_changes_status(): void
    {
        Http::fake([self::GATEWAY.'/projects/*' => Http::response(['data' => $this->projectPayload(['id' => self::MINE, 'status' => 'disabled'])])]);

        $this->actingAs(User::factory()->owner()->create())
            ->patch('/projects/'.self::MINE.'/status', ['status' => 'disabled'])
            ->assertRedirect('/projects/'.self::MINE.'/settings');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['status' => 'disabled'] && $r->hasHeader('Idempotency-Key'));
    }

    public function test_owner_creates_project_with_quotas_and_tasks(): void
    {
        $orgId = '6f1d8a8e-1111-4c4c-9a9a-0000000000e1';
        Http::fake([self::GATEWAY.'/projects' => Http::response(['data' => $this->projectPayload(['id' => self::MINE, 'name' => 'Diary'])], 201)]);

        $this->actingAs(User::factory()->owner()->create())
            ->post('/projects', [
                'organization_id' => $orgId,
                'slug' => 'my-digital-diary',
                'name' => 'Diary',
                'environment' => 'staging',
                'owner_ref' => '',
                'daily_limit' => '50',
                'allowed_tasks' => "daily_plan, summary\nreflection",
            ])
            ->assertRedirect('/projects/'.self::MINE.'/settings');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === [
            'organization_id' => $orgId,
            'slug' => 'my-digital-diary',
            'name' => 'Diary',
            'environment' => 'staging',
            'daily_limit' => 50,
            'allowed_tasks' => ['daily_plan', 'summary', 'reflection'],
        ]);
    }

    public function test_ops_admin_create_does_not_send_quotas(): void
    {
        Http::fake([self::GATEWAY.'/projects' => Http::response(['data' => $this->projectPayload(['id' => self::MINE])], 201)]);

        $this->actingAs(User::factory()->opsAdmin()->create())
            ->post('/projects', [
                'organization_id' => '6f1d8a8e-1111-4c4c-9a9a-0000000000e1',
                'slug' => 'ops-project', 'name' => 'Ops', 'environment' => 'production',
                'daily_limit' => '99999',
            ])->assertRedirect();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! array_key_exists('daily_limit', $r->data()));
    }

    public function test_gateway_validation_errors_surface_on_the_form(): void
    {
        Http::fake([self::GATEWAY.'/projects' => $this->gatewayError(422, 'VALIDATION_FAILED', 'Invalid', ['slug' => ['The slug has already been taken.']])]);

        $this->actingAs(User::factory()->owner()->create())
            ->from('/projects/create')
            ->post('/projects', [
                'organization_id' => '6f1d8a8e-1111-4c4c-9a9a-0000000000e1',
                'slug' => 'taken-slug', 'name' => 'Dup', 'environment' => 'staging',
            ])
            ->assertRedirect('/projects/create')
            ->assertSessionHasErrors(['slug' => 'The slug has already been taken.'])
            ->assertSessionHasInput('name', 'Dup');
    }

    public function test_idempotency_conflict_becomes_a_form_error(): void
    {
        Http::fake([self::GATEWAY.'/projects' => $this->gatewayError(409, 'IDEMPOTENCY_CONFLICT', 'Conflict')]);

        $this->actingAs(User::factory()->owner()->create())
            ->from('/projects/create')
            ->post('/projects', [
                'organization_id' => '6f1d8a8e-1111-4c4c-9a9a-0000000000e1',
                'slug' => 'abc', 'name' => 'Dup', 'environment' => 'staging',
            ])
            ->assertRedirect('/projects/create')
            ->assertSessionHasErrors('form');
    }

    public function test_create_page_lists_active_organizations(): void
    {
        Http::fake([self::GATEWAY.'/organizations*' => $this->page([$this->organizationPayload(['name' => 'Unihub Solutions'])])]);

        $this->actingAs(User::factory()->opsAdmin()->create())
            ->get('/projects/create')->assertOk()->assertSee('Unihub Solutions')->assertSee('name="_idempotency_key"', false);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'status=active'));
    }

    public function test_project_manager_cannot_open_create_page(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->projectManager([self::MINE])->create())->get('/projects/create')->assertForbidden();
    }

    public function test_gateway_down_shows_unreachable_page(): void
    {
        Http::fake([self::GATEWAY.'/*' => Http::failedConnection()]);

        $this->actingAs(User::factory()->owner()->create())
            ->get('/projects/'.self::MINE.'/settings')
            ->assertStatus(503)
            ->assertSee('Gateway unreachable');
    }

    public function test_gateway_data_is_escaped(): void
    {
        Http::fake([self::GATEWAY.'/projects/*' => Http::response(['data' => $this->projectPayload([
            'id' => self::MINE,
            'name' => '<script>alert(1)</script>',
            'owner_ref' => '"><img src=x onerror=alert(2)>',
        ])])]);

        $this->actingAs(User::factory()->owner()->create())
            ->get('/projects/'.self::MINE.'/settings')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_times_are_displayed_in_kampala(): void
    {
        Http::fake([self::GATEWAY.'/projects/*' => Http::response(['data' => $this->projectPayload([
            'id' => self::MINE, 'created_at' => '2026-11-26T09:00:00Z',
        ])])]);

        $this->actingAs(User::factory()->owner()->create())
            ->get('/projects/'.self::MINE.'/settings')
            ->assertSee('26 Nov 2026, 12:00')
            ->assertSee('datetime="2026-11-26T09:00:00Z"', false);
    }
}
