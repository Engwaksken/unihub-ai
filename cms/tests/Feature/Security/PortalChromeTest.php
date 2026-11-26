<?php

namespace Tests\Feature\Security;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class PortalChromeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_menu_shows_every_built_module(): void
    {
        Http::fake([self::GATEWAY.'/*' => $this->page([])]);

        $html = $this->actingAs(User::factory()->owner()->create())->get('/organizations')->assertOk()->getContent();

        foreach (['Overview', 'Projects', 'Organizations', 'API keys', 'Models &amp; tasks', 'Jobs &amp; queue', 'Usage &amp; reports', 'Server &amp; alerts', 'Users &amp; audit', 'Settings'] as $visible) {
            $this->assertStringContainsString('<span>'.$visible.'</span>', $html);
        }
    }

    public function test_switching_a_flag_off_hides_the_menu_item_and_404s_its_routes(): void
    {
        config(['cms.features.overview' => false, 'cms.features.api_keys' => false, 'cms.features.health' => false]);
        Http::fake([self::GATEWAY.'/*' => $this->page([])]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/organizations')
            ->assertDontSee('<span>Overview</span>', false)
            ->assertDontSee('<span>API keys</span>', false)
            ->assertDontSee('<span>Server &amp; alerts</span>', false);
        $this->actingAs($owner)->get('/overview')->assertNotFound();
        $this->actingAs($owner)->get('/keys')->assertNotFound();
        $this->actingAs($owner)->get('/health')->assertNotFound();
        $this->actingAs($owner)->get('/')->assertRedirect('/projects');
    }

    public function test_menu_respects_role(): void
    {
        Http::fake([self::GATEWAY.'/*' => $this->page([])]);

        $this->actingAs(User::factory()->auditor([])->create())->get('/projects')->assertOk()
            ->assertDontSee('<span>Organizations</span>', false)
            ->assertSee('<span>Users &amp; audit</span>', false)
            ->assertSee(route('audit.index'), false);
    }

    public function test_security_headers_and_request_id(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));

        $other = $this->withHeader('X-Request-Id', 'attacker-chosen')->get('/login');
        $this->assertNotSame('attacker-chosen', $other->headers->get('X-Request-Id'));
    }

    public function test_built_assets_and_livewire_scripts_carry_the_csp_nonce(): void
    {
        if (! is_file(public_path('build/manifest.json'))) {
            $this->markTestSkipped('Vite assets are not built (run npm run build).');
        }
        $this->withVite();
        Http::fake([self::GATEWAY.'/*' => $this->page([])]);

        $response = $this->actingAs(User::factory()->owner()->create())->get('/projects')->assertOk();

        preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $m);
        $nonce = $m[1] ?? null;
        $this->assertNotEmpty($nonce);

        $html = $response->getContent();
        $this->assertMatchesRegularExpression('#<link[^>]+/build/assets/app-[^>]+\.css#', $html);
        $this->assertMatchesRegularExpression('#<script[^>]+livewire[^>]*nonce="'.preg_quote($nonce, '#').'"|<script[^>]+nonce="'.preg_quote($nonce, '#').'"[^>]*livewire#', $html);
    }

    public function test_audit_events_are_append_only_and_carry_request_id(): void
    {
        $event = Audit::record('test.event', metadata: ['password' => 'hunter2', 'nested' => ['token' => 'x', 'ok' => 1], 'email' => 'a@example.test']);

        $this->assertTrue(Str::isUuid($event->request_id));
        $this->assertSame(['nested' => ['ok' => 1], 'email' => 'a@example.test'], $event->fresh()->metadata);

        $this->assertThrows(fn () => $event->update(['action' => 'tampered']), LogicException::class);
        $this->assertThrows(fn () => $event->delete(), LogicException::class);
        $this->assertSame('test.event', AuditEvent::query()->sole()->action);
    }

    public function test_audit_page_scopes_project_events_for_project_roles(): void
    {
        $mine = '6f1d8a8e-1111-4c4c-9a9a-0000000000a9';
        $other = '6f1d8a8e-1111-4c4c-9a9a-0000000000b9';
        Audit::record('staff.membership_added', projectUuid: $mine, actorLabel: 'mine-event@example.test');
        Audit::record('staff.membership_added', projectUuid: $other, actorLabel: 'other-event@example.test');
        Audit::record('auth.login_succeeded', actorLabel: 'global-event@example.test');
        Http::fake([self::GATEWAY.'/*' => $this->page([])]);

        $this->actingAs(User::factory()->auditor([$mine])->create())->get('/audit')->assertOk()
            ->assertSee('mine-event@example.test')
            ->assertDontSee('other-event@example.test')
            ->assertDontSee('global-event@example.test');

        $this->actingAs(User::factory()->opsAdmin()->create())->get('/audit')->assertOk()
            ->assertSee('mine-event@example.test')
            ->assertSee('other-event@example.test')
            ->assertSee('global-event@example.test');
    }

    public function test_home_redirects(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->actingAs(User::factory()->owner()->create())->get('/')->assertRedirect('/overview');
    }
}
