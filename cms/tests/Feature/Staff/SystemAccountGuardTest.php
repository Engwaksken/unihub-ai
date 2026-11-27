<?php

namespace Tests\Feature\Staff;

use App\Console\Commands\CreateSystemUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The scheduler's system account must stay enabled: disabling it silently stops gateway checks and alert e-mails. */
class SystemAccountGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_cannot_disable_the_system_account_and_sees_no_button(): void
    {
        Http::fake([self::GATEWAY.'/projects*' => $this->page([])]);
        $this->artisan('cms:system-user')->assertSuccessful();
        $system = CreateSystemUser::find();
        $owner = User::factory()->owner()->create();

        $html = $this->actingAs($owner)->get('/users')->assertOk()->getContent();
        $this->assertStringNotContainsString('action="'.route('users.disable', $system).'"', $html);

        $this->actingAs($owner)->from('/users')->post(route('users.disable', $system))
            ->assertRedirect('/users')->assertSessionHasErrors('form');
        $this->assertFalse($system->fresh()->isDisabled());
    }

    public function test_system_user_command_re_enables_a_disabled_system_account(): void
    {
        $this->artisan('cms:system-user')->assertSuccessful();
        CreateSystemUser::find()->forceFill(['disabled_at' => now()])->save();

        $this->artisan('cms:system-user')->expectsOutputToContain('re-enabled')->assertSuccessful();
        $this->assertFalse(CreateSystemUser::find()->isDisabled());
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.enabled', 'actor_label' => 'console']);
    }

    public function test_disabled_system_account_can_be_enabled_from_the_users_page(): void
    {
        Http::fake([self::GATEWAY.'/projects*' => $this->page([])]);
        $this->artisan('cms:system-user')->assertSuccessful();
        $system = CreateSystemUser::find();
        $system->forceFill(['disabled_at' => now()])->save();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/users')->assertOk()->assertSee('action="'.route('users.enable', $system).'"', false);
        $this->actingAs($owner)->post(route('users.enable', $system))->assertRedirect();
        $this->assertFalse($system->fresh()->isDisabled());
    }
}
