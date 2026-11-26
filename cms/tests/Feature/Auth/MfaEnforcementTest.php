<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function portalRoutes(): array
    {
        return [
            'projects' => ['/projects'],
            'project create' => ['/projects/create'],
            'project settings' => ['/projects/6f1d8a8e-1111-4c4c-9a9a-000000000010/settings'],
            'organizations' => ['/organizations'],
            'users' => ['/users'],
            'audit' => ['/audit'],
            'account' => ['/account'],
        ];
    }

    #[DataProvider('portalRoutes')]
    public function test_user_without_confirmed_two_factor_is_sent_to_enrolment(string $uri): void
    {
        $user = User::factory()->withRoles(Role::Owner)->create();

        $this->actingAs($user)->get($uri)->assertRedirect(route('two-factor.setup'));
        Http::assertNothingSent();
    }

    public function test_unconfirmed_secret_does_not_count_as_enrolled(): void
    {
        $user = User::factory()->withRoles(Role::Owner)->create([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => null,
        ]);

        $this->actingAs($user)->get('/projects')->assertRedirect(route('two-factor.setup'));
    }

    public function test_enrolment_page_is_reachable_without_mfa(): void
    {
        $user = User::factory()->withRoles(Role::Owner)->create();

        $this->actingAs($user)->get('/security/two-factor')->assertOk()->assertSee('Start setup');
    }

    public function test_enrolled_user_reaches_the_portal(): void
    {
        Http::fake([self::GATEWAY.'/*' => $this->page([])]);
        $user = User::factory()->owner()->create();

        $this->actingAs($user)->get('/projects')->assertOk();
    }

    public function test_full_enrolment_flow_with_fortify(): void
    {
        $user = User::factory()->withRoles(Role::Owner)->create();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $this->post('/user/two-factor-authentication')->assertRedirect();
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertFalse($user->hasConfirmedTwoFactor());

        $this->get('/security/two-factor')->assertOk()->assertSee('Confirm and activate');
        $this->get('/projects')->assertRedirect(route('two-factor.setup'));

        $code = (new Google2FA)->getCurrentOtp(decrypt($user->two_factor_secret));
        $this->from('/security/two-factor')
            ->post('/user/confirmed-two-factor-authentication', ['code' => $code])
            ->assertRedirect('/security/two-factor');

        $this->assertTrue($user->fresh()->hasConfirmedTwoFactor());
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.two_factor_enrolled', 'actor_user_id' => $user->id]);
    }

    public function test_login_without_mfa_then_redirected_to_enrolment(): void
    {
        $user = User::factory()->withRoles(Role::Owner)->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'Correct-Horse-9'])->assertRedirect('/projects');
        $this->assertAuthenticatedAs($user);
        $this->get('/projects')->assertRedirect(route('two-factor.setup'));
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.login_succeeded', 'actor_user_id' => $user->id]);
    }

    public function test_login_with_mfa_requires_totp_challenge(): void
    {
        $secret = (new Google2FA)->generateSecretKey();
        $user = User::factory()->owner()->create(['two_factor_secret' => encrypt($secret)]);

        $this->post('/login', ['email' => $user->email, 'password' => 'Correct-Horse-9'])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.two_factor_failed', 'subject_id' => $user->id]);

        $this->post('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertRedirect('/projects');
        $this->assertAuthenticatedAs($user);
    }

    public function test_failed_login_is_audited_without_the_password(): void
    {
        $user = User::factory()->owner()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'Wrong-Password-1'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $event = AuditEvent::query()->where('action', 'auth.login_failed')->sole();
        $this->assertSame($user->email, $event->metadata['email']);
        $this->assertStringNotContainsString('Wrong-Password-1', json_encode($event->getAttributes()));
    }

    public function test_login_is_throttled(): void
    {
        $user = User::factory()->owner()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'Wrong-Password-'.$i]);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'Correct-Horse-9'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_disabled_and_not_yet_activated_users_cannot_sign_in(): void
    {
        $disabled = User::factory()->owner()->disabled()->create();
        $this->post('/login', ['email' => $disabled->email, 'password' => 'Correct-Horse-9'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $invited = User::factory()->invited()->withRoles(Role::OpsAdmin)->create();
        $this->post('/login', ['email' => $invited->email, 'password' => 'Correct-Horse-9'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
