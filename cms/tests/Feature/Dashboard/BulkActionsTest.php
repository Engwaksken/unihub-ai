<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Multi-select edit and delete on /projects, /users and /jobs.
 */
class BulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private const P1 = '6f1d8a8e-1111-4c4c-9a9a-0000000005a1';

    private const P2 = '6f1d8a8e-1111-4c4c-9a9a-0000000005a2';

    private const J1 = '6f1d8a8e-1111-4c4c-9a9a-0000000005c1';

    private const J2 = '6f1d8a8e-1111-4c4c-9a9a-0000000005c2';

    private function fakeProjects(): void
    {
        Http::fake([
            self::GATEWAY.'/projects/*' => fn (Request $r) => Http::response(['data' => $this->projectPayload(['id' => basename(parse_url($r->url(), PHP_URL_PATH))] + $r->data())]),
            self::GATEWAY.'/projects*' => $this->page([
                $this->projectPayload(['id' => self::P1, 'name' => 'Alpha']),
                $this->projectPayload(['id' => self::P2, 'name' => 'Beta']),
            ]),
            self::GATEWAY.'/organizations*' => $this->page([]),
        ]);
    }

    // ---------- projects ----------

    public function test_bulk_edit_sends_only_filled_fields_to_each_project(): void
    {
        $this->fakeProjects();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->from('/projects')->post('/projects/bulk', [
            'action' => 'edit', 'ids' => [self::P1, self::P2], 'environment' => 'production', 'status' => '', 'daily_limit' => '500',
        ])->assertRedirect('/projects')->assertSessionHas('status', 'Updated 2 projects (environment, daily limit).');

        $patches = Http::recorded(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertCount(2, $patches);
        foreach ($patches as [$request]) {
            $this->assertSame(['environment' => 'production', 'daily_limit' => 500], $request->data());
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}:[0-9a-f-]{36}$/', $request->header('Idempotency-Key')[0]);
        }
    }

    public function test_bulk_edit_needs_at_least_one_field(): void
    {
        $this->fakeProjects();
        $this->actingAs(User::factory()->owner()->create())->from('/projects')
            ->post('/projects/bulk', ['action' => 'edit', 'ids' => [self::P1]])
            ->assertSessionHasErrors('form');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_bulk_delete_archives_and_skips_projects_the_user_may_not_change(): void
    {
        $this->fakeProjects();
        // Ops admins may change status; a project manager may not, so nothing is sent for them.
        $this->actingAs(User::factory()->opsAdmin()->create())->from('/projects')
            ->post('/projects/bulk', ['action' => 'delete', 'ids' => [self::P1]])
            ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Archived 1 project.'));
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['status' => 'archived']);

        $pm = User::factory()->projectManager([self::P1])->create();
        $this->actingAs($pm)->from('/projects')->post('/projects/bulk', ['action' => 'edit', 'ids' => [self::P1], 'daily_limit' => '9'])
            ->assertSessionHas('status', 'Updated 0 projects (daily limit).')
            ->assertSessionHas('bulk_skipped', fn ($lines) => str_contains($lines[0], 'You may not change daily limit'));
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->method() === 'PATCH'));
    }

    public function test_project_list_shows_selection_only_to_editors(): void
    {
        $this->fakeProjects();
        $this->actingAs(User::factory()->owner()->create())->get('/projects')->assertOk()
            ->assertSee('id="bulk-projects"', false)->assertSee('Archive projects');
        $this->actingAs(User::factory()->auditor([self::P1])->create())->get('/projects')->assertOk()
            ->assertDontSee('id="bulk-projects"', false);
    }

    // ---------- users ----------

    public function test_bulk_user_delete_only_removes_invited_or_disabled_accounts(): void
    {
        Http::fake([self::GATEWAY.'/projects*' => $this->page([])]);
        $owner = User::factory()->owner()->create();
        $invited = User::factory()->opsAdmin()->create(['password_set_at' => null]);
        $disabled = User::factory()->opsAdmin()->create(['disabled_at' => now()]);
        $active = User::factory()->opsAdmin()->create();

        $this->actingAs($owner)->from('/users')->post('/users/bulk', [
            'action' => 'delete', 'ids' => [$invited->id, $disabled->id, $active->id, $owner->id],
        ])->assertRedirect('/users')
            ->assertSessionHas('status', 'Deleted 2 accounts. Their history stays in the audit log.')
            ->assertSessionHas('bulk_skipped', fn ($lines) => count($lines) === 2
                && str_contains(implode(' ', $lines), 'Disable the account first')
                && str_contains(implode(' ', $lines), 'cannot delete your own account'));

        $this->assertModelMissing($invited);
        $this->assertModelMissing($disabled);
        $this->assertModelExists($active);
        $this->assertSame(2, AuditEvent::where('action', 'staff.deleted')->count());
    }

    public function test_bulk_disable_enable_and_role_changes(): void
    {
        Http::fake([self::GATEWAY.'/projects*' => $this->page([])]);
        $owner = User::factory()->owner()->create();
        $a = User::factory()->opsAdmin()->create();
        $b = User::factory()->projectManager([self::P1])->create();

        $this->actingAs($owner)->post('/users/bulk', ['action' => 'disable', 'ids' => [$a->id, $b->id]])
            ->assertSessionHas('status', 'Disabled 2 accounts and signed them out everywhere.');
        $this->assertTrue($a->fresh()->isDisabled());
        $this->actingAs($owner)->post('/users/bulk', ['action' => 'enable', 'ids' => [$a->id, $b->id]]);
        $this->assertFalse($b->fresh()->isDisabled());

        $this->actingAs($owner)->post('/users/bulk', ['action' => 'role', 'role' => 'auditor', 'role_change' => 'add', 'ids' => [$a->id, $b->id]])
            ->assertSessionHas('status', 'Added the '.Role::Auditor->label().' role to 2 accounts.');
        $this->assertTrue($a->fresh()->hasRole(Role::Auditor));

        // Removing project manager drops the memberships held with it; removing someone's only role is refused.
        $this->actingAs($owner)->post('/users/bulk', ['action' => 'role', 'role' => 'project_manager', 'role_change' => 'remove', 'ids' => [$b->id]]);
        $this->assertFalse($b->fresh()->hasRole(Role::ProjectManager));
        $this->assertSame(0, $b->fresh()->memberships()->count());
        $this->actingAs($owner)->post('/users/bulk', ['action' => 'role', 'role' => 'auditor', 'role_change' => 'remove', 'ids' => [$b->id]])
            ->assertSessionHas('bulk_skipped', fn ($lines) => str_contains($lines[0], 'only role'));
    }

    public function test_last_owner_is_protected_and_non_owners_cannot_bulk_edit_users(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs(User::factory()->opsAdmin()->create())->post('/users/bulk', ['action' => 'disable', 'ids' => [$owner->id]])->assertForbidden();

        $this->actingAs($owner)->post('/users/bulk', ['action' => 'role', 'role' => 'owner', 'role_change' => 'remove', 'ids' => [$owner->id]])
            ->assertSessionHas('bulk_skipped');
        $this->assertTrue($owner->fresh()->hasRole(Role::Owner));
        $this->actingAs($owner)->post('/users/bulk', ['action' => 'delete'])->assertSessionHasErrors('form');
    }

    // ---------- jobs ----------

    public function test_bulk_job_delete_reports_deleted_and_skipped(): void
    {
        Http::fake([self::GATEWAY.'/jobs/delete' => Http::response(['data' => ['deleted' => [self::J1], 'skipped' => [['id' => self::J2, 'reason' => 'not_finished']]]])]);

        $this->actingAs(User::factory()->opsAdmin()->create())->from('/jobs')
            ->post('/jobs/bulk', ['action' => 'delete', 'ids' => [self::J1, self::J2]])
            ->assertRedirect('/jobs')
            ->assertSessionHas('status', 'Deleted 1 job. Usage totals are unchanged.')
            ->assertSessionHas('bulk_skipped', fn ($lines) => str_contains($lines[0], 'still queued or running'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/jobs/delete') && $r->data() === ['ids' => [self::J1, self::J2]]);
    }

    public function test_project_managers_may_bulk_cancel_but_not_delete(): void
    {
        Http::fake([
            self::GATEWAY.'/jobs/'.self::J1.'/cancel' => Http::response(['data' => ['id' => self::J1, 'state' => 'cancelled']]),
            self::GATEWAY.'/jobs/'.self::J2.'/cancel' => $this->gatewayError(409, 'INVALID_STATE', 'Only queued jobs can be cancelled'),
        ]);
        $pm = User::factory()->projectManager([self::P1])->create();

        $this->actingAs($pm)->post('/jobs/bulk', ['action' => 'delete', 'ids' => [self::J1]])->assertForbidden();
        $this->actingAs($pm)->from('/jobs')->post('/jobs/bulk', ['action' => 'cancel', 'ids' => [self::J1, self::J2]])
            ->assertSessionHas('status', 'Cancelled 1 job.')
            ->assertSessionHas('bulk_skipped', fn ($lines) => str_contains($lines[0], 'Only queued jobs can be cancelled'));
    }

    public function test_bulk_retry_requires_a_reason(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->owner()->create())->from('/jobs')
            ->post('/jobs/bulk', ['action' => 'retry', 'ids' => [self::J1], 'reason' => ''])
            ->assertSessionHasErrors('form');
        Http::assertNothingSent();
    }

    // ---------- settings ----------

    public function test_settings_sections_are_tabs_and_open_from_the_query(): void
    {
        Http::fake([self::GATEWAY.'/settings' => Http::response(['data' => []])]);
        $html = $this->actingAs(User::factory()->owner()->create())->get('/settings?tab=appearance')->assertOk()->getContent();

        $this->assertStringContainsString('id="settings-tab-portal"', $html);
        $this->assertStringContainsString('id="settings-tab-appearance"', $html);
        $this->assertStringContainsString('id="settings-tab-policy"', $html);
        $this->assertMatchesRegularExpression("/tab: 'appearance'/", $html);
    }
}
