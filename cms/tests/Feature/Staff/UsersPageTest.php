<?php

namespace Tests\Feature\Staff;

use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class UsersPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([self::GATEWAY.'/projects*' => $this->page([])]);
    }

    public function test_statistics_cards_count_staff_by_status(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->opsAdmin()->create(['password_set_at' => null]);                       // invited
        User::factory()->auditor([])->create(['disabled_at' => now()]);                         // disabled
        User::factory()->opsAdmin()->create(['two_factor_confirmed_at' => null]);              // 2FA pending

        $html = $this->actingAs($owner)->get('/users')->assertOk()->getContent();

        foreach (['Staff accounts' => 4, 'Active' => 1, 'Invitations pending' => 1, '2FA pending' => 1, 'Disabled' => 1] as $label => $n) {
            $this->assertMatchesRegularExpression('#'.preg_quote($label, '#').'</dt>\s*<dd class="metric-value">\s*'.$n.'\b#', $html, $label);
        }
    }

    public function test_search_role_and_status_filters_with_pagination(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Olive Owner']);
        User::factory()->opsAdmin()->create(['name' => 'Oscar Ops', 'email' => 'oscar@witulibrary.org']);
        User::factory()->auditor([])->create(['name' => 'Ada Auditor', 'disabled_at' => now()]);

        $this->actingAs($owner)->get('/users?q=witulibrary')->assertOk()
            ->assertSee('Oscar Ops')->assertDontSee('Ada Auditor')->assertSee('Clear');
        $this->actingAs($owner)->get('/users?role=auditor')->assertOk()
            ->assertSee('Ada Auditor')->assertDontSee('Oscar Ops');
        $this->actingAs($owner)->get('/users?status=disabled')->assertOk()
            ->assertSee('Ada Auditor')->assertDontSee('Oscar Ops');
        $this->actingAs($owner)->get('/users?q=%25')->assertOk()->assertSee('No staff match these filters'); // % is literal
        $this->actingAs($owner)->get('/users?status=bogus')->assertSessionHasErrors('status');

        User::factory()->count(30)->opsAdmin()->create();
        $this->actingAs($owner)->get('/users?role=ops_admin')->assertOk()
            ->assertSee('of 31')->assertSee('role=ops_admin&amp;page=2', false); // filters survive paging
    }

    public function test_invite_and_edit_are_modals_that_reopen_on_validation_error(): void
    {
        $owner = User::factory()->owner()->create();
        $other = User::factory()->opsAdmin()->create();

        $html = $this->actingAs($owner)->get('/users')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<dialog[^>]*>.*?<form method="POST" action="[^"]+/users" class="form">#s', $html);
        $this->assertStringContainsString('action="'.route('users.update', $other).'"', $html);

        $this->actingAs($owner)->from('/users')
            ->post('/users', ['_modal' => 'invite', 'name' => 'X', 'email' => 'not-an-email', 'roles' => ['auditor']])
            ->assertRedirect('/users')->assertSessionHasErrors('email');
        $reopened = $this->actingAs($owner)->get('/users')->getContent();
        $this->assertStringContainsString('x-init="$nextTick(() =&gt; $refs.dialog.showModal())"', $reopened);
        $this->assertStringContainsString('value="not-an-email"', $reopened);
    }

    public function test_mail_failure_keeps_the_account_and_shows_the_link_once(): void
    {
        Notification::fake();
        Notification::shouldReceive('send')->andThrow(new TransportException('Connection could not be established with host "mail.witulibrary.org:465"'));
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/users', [
            'name' => 'Pat', 'email' => 'pat@example.test', 'roles' => ['auditor'],
        ])->assertRedirect('/users')->assertSessionHasNoErrors()->assertSessionHas('invitation_mail_failed');

        $this->assertDatabaseHas('users', ['email' => 'pat@example.test']);
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.invitation_mail_failed']);

        $page = $this->actingAs($owner)->get('/users')->assertOk()
            ->assertSee('could not be sent')->assertSee('mail.witulibrary.org:465')->assertSee('Copy link');
        $this->assertMatchesRegularExpression('#/invitations/[A-Za-z0-9]{48}#', $page->getContent());
        $this->assertDoesNotMatchRegularExpression('#/invitations/[A-Za-z0-9]{48}#', $this->actingAs($owner)->get('/users')->getContent()); // shown once
    }

    public function test_resend_reports_mail_success(): void
    {
        Notification::fake();
        $owner = User::factory()->owner()->create();
        $invitee = User::factory()->opsAdmin()->create(['password_set_at' => null]);

        $this->actingAs($owner)->post("/users/{$invitee->id}/invitation")->assertRedirect('/users')->assertSessionHas('status');
        Notification::assertSentTo($invitee, StaffInvitation::class);
    }
}
