<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class TestMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sends_a_test_mail_to_themselves(): void
    {
        Http::fake([self::GATEWAY.'/*' => Http::response(['data' => []])]);
        $owner = User::factory()->owner()->create(['email' => 'owner@witulibrary.org']);

        $this->actingAs($owner)->get('/settings')->assertOk()->assertSee('Send test e-mail to owner@witulibrary.org');
        $this->actingAs($owner)->from('/settings')->post('/settings/test-mail')
            ->assertRedirect('/settings')->assertSessionHas('status');
        $this->assertDatabaseHas('audit_events', ['action' => 'settings.test_mail']);
    }

    public function test_smtp_failure_shows_the_mail_server_answer(): void
    {
        Mail::shouldReceive('raw')->andThrow(new TransportException('Failed to authenticate on SMTP server with username "no-reply@witulibrary.org"'));
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->from('/settings')->post('/settings/test-mail')
            ->assertRedirect('/settings')
            ->assertSessionHasErrorsIn('mailTest', 'mail');
    }

    public function test_roles_without_settings_access_may_not_send_it(): void
    {
        $this->actingAs(User::factory()->auditor([])->create())->post('/settings/test-mail')->assertForbidden();
        $this->actingAs(User::factory()->projectManager([])->create())->post('/settings/test-mail')->assertForbidden();
    }
}
