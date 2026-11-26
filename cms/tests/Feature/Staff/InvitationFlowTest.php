<?php

namespace Tests\Feature\Staff;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '6f1d8a8e-1111-4c4c-9a9a-0000000000a1';

    private function invite(User $owner, array $overrides = []): TestResponse
    {
        return $this->actingAs($owner)->post('/users', array_merge([
            'name' => 'Pat Manager',
            'email' => 'pat@example.test',
            'roles' => ['project_manager'],
            'memberships' => [self::PROJECT => 'project_manager'],
        ], $overrides));
    }

    private function tokenFor(User $user): string
    {
        $token = null;
        Notification::assertSentTo($user, StaffInvitation::class, function (StaffInvitation $n) use (&$token, $user) {
            preg_match('#/invitations/([A-Za-z0-9]{48})#', $n->toMail($user)->actionUrl, $m);
            $token = $m[1] ?? null;

            return true;
        });

        return $token;
    }

    public function test_invite_form_lists_gateway_projects(): void
    {
        Http::fake([self::GATEWAY.'/projects*' => $this->page([$this->projectPayload(['id' => self::PROJECT, 'name' => 'Diary'])])]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/users/invite')->assertOk()->assertSee('Diary')->assertSee(self::PROJECT);
    }

    public function test_invite_form_still_works_when_gateway_is_down(): void
    {
        Http::fake([self::GATEWAY.'/*' => Http::failedConnection()]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/users/invite')->assertOk()->assertSee('gateway is unreachable');
    }

    public function test_owner_invites_staff_who_sets_password_then_must_enrol_mfa(): void
    {
        Notification::fake();
        $owner = User::factory()->owner()->create();

        $this->invite($owner)->assertRedirect('/users')->assertSessionHasNoErrors();

        $invitee = User::query()->where('email', 'pat@example.test')->sole();
        $this->assertTrue($invitee->hasRole(Role::ProjectManager));
        $this->assertTrue($invitee->hasMembership(self::PROJECT, Role::ProjectManager));
        $this->assertFalse($invitee->hasSetPassword());

        $token = $this->tokenFor($invitee);
        $this->assertDatabaseMissing('invitations', ['token_hash' => $token]);

        auth()->logout();

        $this->get('/invitations/'.$token)->assertOk()->assertSee('pat@example.test');
        $this->post('/invitations/'.$token, ['password' => 'Pat-Secure-Pass1', 'password_confirmation' => 'Pat-Secure-Pass1'])
            ->assertRedirect(route('two-factor.setup'));

        $this->assertAuthenticatedAs($invitee);
        $this->assertTrue($invitee->fresh()->hasSetPassword());
        $this->get('/projects')->assertRedirect(route('two-factor.setup'));

        // Single use.
        auth()->logout();
        $this->get('/invitations/'.$token)->assertStatus(410);
        $this->post('/invitations/'.$token, ['password' => 'Other-Secure-Pass1', 'password_confirmation' => 'Other-Secure-Pass1'])->assertStatus(410);

        $this->assertDatabaseHas('audit_events', ['action' => 'staff.invited', 'actor_user_id' => $owner->id, 'subject_id' => $invitee->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.membership_added', 'gateway_project_uuid' => self::PROJECT]);
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.invitation_accepted', 'subject_id' => $invitee->id]);

        foreach (AuditEvent::all() as $event) {
            $raw = json_encode($event->getAttributes());
            $this->assertStringNotContainsString($token, $raw);
            $this->assertStringNotContainsString('Pat-Secure-Pass1', $raw);
        }
    }

    public function test_invitation_expires_after_24_hours(): void
    {
        Notification::fake();
        $owner = User::factory()->owner()->create();
        $this->invite($owner);
        $token = $this->tokenFor(User::query()->where('email', 'pat@example.test')->sole());
        auth()->logout();

        $this->travel(24)->hours();
        $this->travel(1)->minutes();

        $this->get('/invitations/'.$token)->assertStatus(410);
        $this->post('/invitations/'.$token, ['password' => 'Pat-Secure-Pass1', 'password_confirmation' => 'Pat-Secure-Pass1'])->assertStatus(410);
        $this->assertGuest();
    }

    public function test_resending_revokes_the_previous_link(): void
    {
        Notification::fake();
        $owner = User::factory()->owner()->create();
        $this->invite($owner);
        $invitee = User::query()->where('email', 'pat@example.test')->sole();
        $first = $this->tokenFor($invitee);

        $this->actingAs($owner)->post("/users/{$invitee->id}/invitation")->assertRedirect();
        auth()->logout();

        $this->get('/invitations/'.$first)->assertStatus(410);
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.invitation_reissued', 'subject_id' => $invitee->id]);
    }

    public function test_unknown_token_is_rejected(): void
    {
        $this->get('/invitations/'.str_repeat('a', 48))->assertStatus(410);
    }

    public function test_membership_requires_matching_role(): void
    {
        Notification::fake();
        $owner = User::factory()->owner()->create();

        $this->invite($owner, ['roles' => ['ops_admin'], 'memberships' => [self::PROJECT => 'auditor']])
            ->assertSessionHasErrors('memberships');
        $this->assertDatabaseMissing('users', ['email' => 'pat@example.test']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        Notification::fake();
        $owner = User::factory()->owner()->create();
        User::factory()->create(['email' => 'pat@example.test']);

        $this->invite($owner)->assertSessionHasErrors('email');
        Notification::assertNothingSent();
    }

    public function test_only_owners_can_invite(): void
    {
        Notification::fake();
        foreach ([User::factory()->opsAdmin()->create(), User::factory()->projectManager([self::PROJECT])->create(), User::factory()->auditor([self::PROJECT])->create()] as $user) {
            $this->actingAs($user)->get('/users/invite')->assertForbidden();
            $this->actingAs($user)->post('/users', [
                'name' => 'X', 'email' => 'x@example.test', 'roles' => ['owner'],
            ])->assertForbidden();
        }
        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);
        Notification::assertNothingSent();
    }

    public function test_owner_changes_roles_and_memberships_with_audit(): void
    {
        $owner = User::factory()->owner()->create();
        $other = '6f1d8a8e-1111-4c4c-9a9a-0000000000b2';
        $user = User::factory()->projectManager([self::PROJECT])->create();

        $this->actingAs($owner)->put("/users/{$user->id}", [
            'name' => $user->name,
            'roles' => ['project_manager', 'auditor'],
            'memberships' => [self::PROJECT => 'none', $other => 'auditor'],
        ])->assertRedirect('/users')->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertEqualsCanonicalizing(['project_manager', 'auditor'], $user->roleValues());
        $this->assertFalse($user->hasMembership(self::PROJECT));
        $this->assertTrue($user->hasMembership($other, Role::Auditor));

        $this->assertDatabaseHas('audit_events', ['action' => 'staff.roles_changed', 'subject_id' => $user->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.membership_removed', 'gateway_project_uuid' => self::PROJECT]);
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.membership_added', 'gateway_project_uuid' => $other]);
    }

    public function test_last_owner_cannot_be_demoted_or_disabled(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put("/users/{$owner->id}", ['name' => $owner->name, 'roles' => ['ops_admin']])
            ->assertSessionHasErrors('roles');
        $this->assertTrue($owner->fresh()->hasRole(Role::Owner));

        $this->actingAs($owner)->post("/users/{$owner->id}/disable")->assertSessionHasErrors('form');
        $this->assertFalse($owner->fresh()->isDisabled());
    }
}
