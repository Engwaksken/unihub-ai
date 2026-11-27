<?php

namespace Tests\Feature\Staff;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercise the real database session store used in production.
        config(['session.driver' => 'database', 'session.encrypt' => false]);
    }

    /**
     * Simulate a new HTTP request: no cached guard user or session store.
     */
    private function freshRequestState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    /**
     * Persist a logged-in database session for the user and return its id.
     */
    private function storeSessionFor(User $user): string
    {
        $this->freshRequestState();
        $store = $this->app['session']->driver('database');
        $store->setId(Str::random(40));
        $store->start();
        $store->put(Auth::guard('web')->getName(), $user->getAuthIdentifier());
        $store->put('_token', Str::random(40));
        $store->save();

        DB::table('sessions')->where('id', $store->getId())->update(['user_id' => $user->id]);

        return $store->getId();
    }

    public function test_revoking_sessions_signs_the_user_out(): void
    {
        Http::fake([self::GATEWAY.'/*' => $this->page([])]);
        $owner = User::factory()->owner()->create();
        $victim = User::factory()->opsAdmin()->create();

        $sessionId = $this->storeSessionFor($victim);
        $this->storeSessionFor($victim);
        $this->assertSame(2, $victim->activeSessionCount());

        // The stored session is valid before revocation.
        $this->freshRequestState();
        $this->withCookie(config('session.cookie'), $sessionId)->get('/organizations')->assertOk();

        $this->freshRequestState();
        $this->actingAs($owner)->delete("/users/{$victim->id}/sessions")->assertRedirect();
        $this->assertSame(0, $victim->activeSessionCount());
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.sessions_revoked', 'subject_id' => $victim->id, 'actor_user_id' => $owner->id]);

        $this->freshRequestState();
        $this->withCookie(config('session.cookie'), $sessionId)->get('/organizations')->assertRedirect('/login');
    }

    public function test_disabling_a_user_revokes_sessions_and_blocks_existing_logins(): void
    {
        $owner = User::factory()->owner()->create();
        $victim = User::factory()->opsAdmin()->create();
        $this->storeSessionFor($victim);

        $this->actingAs($owner)->post("/users/{$victim->id}/disable")->assertRedirect();

        $this->assertTrue($victim->fresh()->isDisabled());
        $this->assertSame(0, $victim->activeSessionCount());
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.disabled', 'subject_id' => $victim->id]);

        // Even an in-memory authenticated user is thrown out on the next request.
        $this->actingAs($victim->fresh())->get('/projects')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_only_owner_can_revoke_sessions(): void
    {
        $ops = User::factory()->opsAdmin()->create();
        $victim = User::factory()->opsAdmin()->create();
        $this->storeSessionFor($victim);

        $this->actingAs($ops)->delete("/users/{$victim->id}/sessions")->assertForbidden();
        $this->assertSame(1, $victim->activeSessionCount());
    }

    public function test_users_page_lists_session_counts(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Olive Owner']);
        $victim = User::factory()->opsAdmin()->create(['name' => 'Victor Ops']);
        $this->storeSessionFor($victim);
        Http::fake([self::GATEWAY.'/projects*' => $this->page([])]); // the invite/edit modals list projects

        $this->actingAs($owner)->get('/users')->assertOk()->assertSee('Victor Ops')->assertSee('Revoke sessions');
    }
}
