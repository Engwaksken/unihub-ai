<?php

namespace Tests\Feature\Staff;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BootstrapOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_owner_with_unusable_password_and_prints_single_use_link(): void
    {
        $this->assertSame(0, Artisan::call('cms:bootstrap-owner', ['email' => 'First.Owner@example.test', 'name' => 'First Owner']));
        $output = Artisan::output();

        $user = User::query()->where('email', 'first.owner@example.test')->sole();
        $this->assertTrue($user->hasRole(Role::Owner));
        $this->assertFalse($user->hasSetPassword());
        $this->assertFalse($user->hasConfirmedTwoFactor());

        $this->assertMatchesRegularExpression('#/invitations/([A-Za-z0-9]{48})#', $output);
        preg_match('#/invitations/([A-Za-z0-9]{48})#', $output, $m);
        $token = $m[1];

        $invitation = Invitation::query()->sole();
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->between(now()->addHours(23)->addMinutes(59), now()->addHours(24)->addMinute()));

        // The owner cannot sign in before setting a password.
        $this->post('/login', ['email' => 'first.owner@example.test', 'password' => ''])->assertSessionHasErrors();
        $this->assertGuest();

        $this->assertDatabaseHas('audit_events', ['action' => 'staff.invited', 'subject_id' => $user->id]);
    }

    public function test_refuses_when_an_owner_already_exists(): void
    {
        Artisan::call('cms:bootstrap-owner', ['email' => 'first@example.test', 'name' => 'First']);

        $this->assertSame(1, Artisan::call('cms:bootstrap-owner', ['email' => 'second@example.test', 'name' => 'Second']));
        $this->assertStringContainsString('An owner already exists', Artisan::output());
        $this->assertDatabaseMissing('users', ['email' => 'second@example.test']);
        $this->assertSame(1, User::query()->count());
    }

    public function test_refuses_when_any_owner_exists_even_if_created_otherwise(): void
    {
        User::factory()->owner()->create();

        $this->artisan('cms:bootstrap-owner', ['email' => 'x@example.test', 'name' => 'X'])->assertFailed();
    }

    public function test_bootstrap_link_sets_password_then_forces_two_factor_enrolment(): void
    {
        Artisan::call('cms:bootstrap-owner', ['email' => 'owner@example.test', 'name' => 'Owner']);
        preg_match('#/invitations/([A-Za-z0-9]{48})#', Artisan::output(), $m);

        $this->post('/invitations/'.$m[1], ['password' => 'Brand-New-Passw0rd', 'password_confirmation' => 'Brand-New-Passw0rd'])
            ->assertRedirect(route('two-factor.setup'));
        $this->assertAuthenticated();
        $this->get('/projects')->assertRedirect(route('two-factor.setup'));
    }

    public function test_expired_bootstrap_link_can_be_reissued_for_a_never_activated_account(): void
    {
        Artisan::call('cms:bootstrap-owner', ['email' => 'owner@example.test', 'name' => 'Owner']);
        preg_match('#/invitations/([A-Za-z0-9]{48})#', Artisan::output(), $m);

        $this->travel(25)->hours();
        $this->get('/invitations/'.$m[1])->assertStatus(410);

        $this->assertSame(0, Artisan::call('cms:reissue-invitation', ['email' => 'owner@example.test']));
        preg_match('#/invitations/([A-Za-z0-9]{48})#', Artisan::output(), $m2);
        $this->get('/invitations/'.$m2[1])->assertOk();
        $this->get('/invitations/'.$m[1])->assertStatus(410);
    }
}
