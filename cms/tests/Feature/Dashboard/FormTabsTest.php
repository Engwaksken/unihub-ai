<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Long forms are split into tabs; a failed submit opens the tab holding the first error. */
class FormTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            self::GATEWAY.'/projects*' => $this->page([$this->projectPayload(['name' => 'Elevate Her'])]),
            self::GATEWAY.'/settings*' => Http::response(['data' => ['global_queue_limit' => 8, 'max_output_tokens' => 256]]),
        ]);
    }

    public function test_invite_dialog_has_details_roles_and_projects_tabs(): void
    {
        $html = $this->actingAs(User::factory()->owner()->create())->get('/users')->assertOk()->getContent();

        $this->assertStringContainsString('role="tablist" aria-label="Invitation"', $html);
        foreach (['details', 'roles', 'projects'] as $tab) {
            $this->assertStringContainsString('id="invite-tab-'.$tab.'"', $html);
            $this->assertStringContainsString('id="invite-panel-'.$tab.'"', $html);
        }
        $this->assertStringContainsString("x-data=\"{ tab: 'details'", $html); // opens on Details
        $this->assertStringContainsString('Elevate Her', $html);                      // projects tab content
    }

    public function test_failed_submit_opens_the_tab_with_the_error(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->from('/users')
            ->post('/users', ['_modal' => 'invite', 'name' => 'Pat', 'email' => 'pat@example.test', 'roles' => []])
            ->assertRedirect('/users'); // (assertSessionHasErrors would consume the flashed errors)

        $html = $this->actingAs($owner)->get('/users')->getContent();
        $this->assertStringContainsString("x-data=\"{ tab: 'roles'", $html);
        $this->assertMatchesRegularExpression('#id="invite-tab-roles".*?tabset-error#s', $html);
    }

    public function test_gateway_policy_settings_are_tabbed_by_group(): void
    {
        $html = $this->actingAs(User::factory()->owner()->create())->get('/settings')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Gateway policy sections"', $html);
        $this->assertStringContainsString('id="policy-tab-queue-quotas"', $html);
        $this->assertStringContainsString('id="policy-tab-alerts-telemetry"', $html);
    }
}
