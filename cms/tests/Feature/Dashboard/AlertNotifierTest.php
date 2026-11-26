<?php

namespace Tests\Feature\Dashboard;

use App\Console\Commands\CreateSystemUser;
use App\Gateway\AssertionSigner;
use App\Mail\GatewayAlertMail;
use App\Models\User;
use App\Services\CmsSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AlertNotifierTest extends TestCase
{
    use RefreshDatabase;

    private function alert(string $id, string $type = 'ram_high'): array
    {
        return ['id' => $id, 'type' => $type, 'severity' => 'warning', 'status' => 'open', 'message' => 'RAM usage 91%',
            'first_seen_at' => '2026-11-26T08:00:00Z', 'last_seen_at' => '2026-11-26T08:00:00Z', 'acked_by' => null, 'acked_at' => null, 'resolved_at' => null];
    }

    private function setUpNotifier(array $recipients = ['ops@example.test', 'oncall@example.test']): User
    {
        Artisan::call('cms:system-user');
        $owner = User::factory()->owner()->create();
        app(CmsSettings::class)->update(['notification_recipients' => $recipients], $owner);

        return CreateSystemUser::find();
    }

    public function test_system_user_is_least_privilege_and_cannot_sign_in(): void
    {
        $this->assertSame(0, Artisan::call('cms:system-user'));
        $this->assertSame(0, Artisan::call('cms:system-user')); // idempotent
        $system = CreateSystemUser::find();

        $this->assertTrue($system->isSystem());
        $this->assertSame(['auditor'], $system->roleValues());
        $this->assertSame([], $system->assignedProjectUuids());
        $this->assertFalse($system->hasSetPassword());
        $this->assertSame(1, User::query()->where('is_system', true)->count());

        $this->post('/login', ['email' => $system->email, 'password' => ''])->assertSessionHasErrors();
        $this->assertGuest();

        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->get('/users/'.$system->id.'/edit')->assertForbidden();
        $this->actingAs($owner)->post('/users/'.$system->id.'/invitation')->assertSessionHasErrors('form');
    }

    public function test_system_assertion_claims(): void
    {
        $system = $this->setUpNotifier();

        $claims = $this->decodeAssertion(app(AssertionSigner::class)->sign($system))['payload'];

        $this->assertSame($system->id, $claims['sub']);
        $this->assertSame('CMS alert notifier (system)', $claims['name']);
        $this->assertSame(['auditor'], $claims['roles']);
        $this->assertSame([], $claims['projects']);
        $this->assertSame([], $claims['project_roles']);
        $this->assertTrue($claims['mfa']);
    }

    public function test_each_alert_is_emailed_once_per_recipient(): void
    {
        Mail::fake();
        $this->setUpNotifier();
        Http::fake([self::GATEWAY.'/alerts*' => $this->page([$this->alert('6f1d8a8e-1111-4c4c-9a9a-0000000007a1'), $this->alert('6f1d8a8e-1111-4c4c-9a9a-0000000007a2', 'disk_high')])]);

        $this->artisan('cms:notify-alerts')->assertSuccessful();
        $this->artisan('cms:notify-alerts')->assertSuccessful(); // same alerts again: no new mail

        Mail::assertSent(GatewayAlertMail::class, 4); // 2 alerts × 2 recipients
        Mail::assertSent(GatewayAlertMail::class, fn (GatewayAlertMail $m) => $m->hasTo('ops@example.test') && ! $m->hasTo('oncall@example.test'));
        $this->assertDatabaseCount('alert_notifications', 2);

        Http::assertSent(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $claims = $this->decodeAssertion($r->header('X-Actor-Assertion')[0])['payload'];

            return ($q['status'] ?? null) === 'open' && $claims['roles'] === ['auditor'] && $claims['sub'] === CreateSystemUser::find()->id;
        });
    }

    public function test_new_alert_later_is_sent_but_old_ones_are_not_repeated(): void
    {
        Mail::fake();
        $this->setUpNotifier(['ops@example.test']);
        $first = $this->alert('6f1d8a8e-1111-4c4c-9a9a-0000000007b1');
        Http::fake([self::GATEWAY.'/alerts*' => Http::sequence()
            ->push(['data' => [$first], 'next_cursor' => null])
            ->push(['data' => [$first, $this->alert('6f1d8a8e-1111-4c4c-9a9a-0000000007b2', 'tls_expiring')], 'next_cursor' => null])]);

        $this->artisan('cms:notify-alerts');
        $this->artisan('cms:notify-alerts');

        Mail::assertSent(GatewayAlertMail::class, 2);
        Mail::assertSent(GatewayAlertMail::class, fn (GatewayAlertMail $m) => ($m->alert['type'] ?? null) === 'tls_expiring');
    }

    public function test_without_recipients_nothing_is_marked_notified(): void
    {
        Mail::fake();
        $this->setUpNotifier([]);
        Http::fake([self::GATEWAY.'/alerts*' => $this->page([$this->alert('6f1d8a8e-1111-4c4c-9a9a-0000000007c1')])]);

        $this->artisan('cms:notify-alerts')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertDatabaseCount('alert_notifications', 0);
    }

    public function test_failed_mail_is_retried_on_next_run(): void
    {
        $this->setUpNotifier(['ops@example.test']);
        Http::fake([self::GATEWAY.'/alerts*' => $this->page([$this->alert('6f1d8a8e-1111-4c4c-9a9a-0000000007d1')])]);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP down'));

        $this->artisan('cms:notify-alerts');
        $this->assertDatabaseCount('alert_notifications', 0);
    }

    public function test_local_unreachable_alert_after_two_minutes_once_then_recovery(): void
    {
        Mail::fake();
        $this->setUpNotifier(['ops@example.test']);
        $up = false;
        Http::fake([self::GATEWAY.'/*' => function () use (&$up) {
            return $up ? $this->page([]) : Http::failedConnection();
        }]);

        $this->travelTo('2026-11-26T09:00:00Z');
        $this->artisan('cms:notify-alerts');
        $this->travelTo('2026-11-26T09:01:00Z');
        $this->artisan('cms:notify-alerts');
        Mail::assertNothingSent();

        $this->travelTo('2026-11-26T09:02:00Z');
        $this->artisan('cms:notify-alerts');
        $this->travelTo('2026-11-26T09:03:00Z');
        $this->artisan('cms:notify-alerts');

        Mail::assertSent(GatewayAlertMail::class, 1);
        Mail::assertSent(GatewayAlertMail::class, fn (GatewayAlertMail $m) => $m->kind === 'local_unreachable');
        $this->assertDatabaseHas('alert_notifications', ['alert_type' => 'gateway_unreachable']);

        // The Server & alerts page shows the local alert.
        $this->actingAs(User::factory()->opsAdmin()->create())->get('/health')->assertOk()->assertSee('CMS alert: gateway unreachable');

        // Recovery.
        $up = true;
        $this->travelTo('2026-11-26T09:04:00Z');
        $this->artisan('cms:notify-alerts');
        Mail::assertSent(GatewayAlertMail::class, fn (GatewayAlertMail $m) => $m->kind === 'local_recovered');
        $this->assertDatabaseCount('system_state', 0);
    }

    public function test_notifier_skips_without_system_user(): void
    {
        Http::fake();
        $this->artisan('cms:notify-alerts')->expectsOutputToContain('cms:system-user')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_notifier_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'cms:notify-alerts'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_alert_mail_is_plain_text_and_escapes_gateway_text(): void
    {
        $mail = new GatewayAlertMail($this->alert('a1') + ['message' => '<b>RAM</b> high']);
        $rendered = $mail->render();

        $this->assertStringContainsString('ram_high', $rendered);
        $this->assertStringNotContainsString('<b>', $rendered);
    }
}
