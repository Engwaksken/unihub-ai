<?php

namespace Tests\Feature\Dashboard;

use App\Http\Controllers\SettingsController;
use App\Models\User;
use App\Services\CmsSettings;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuditAndSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const A = '6f1d8a8e-1111-4c4c-9a9a-0000000006a1';

    private const B = '6f1d8a8e-1111-4c4c-9a9a-0000000006b2';

    private function gatewayEvent(string $project, string $action, string $time = '2026-11-26T10:00:00Z'): array
    {
        return ['id' => 'g-'.$action, 'source' => 'gateway', 'actor_ref' => 'cms:someone', 'actor_name' => 'Gateway Actor', 'action' => $action,
            'target_type' => 'api_key', 'target_id' => 'k1', 'project_id' => $project, 'metadata' => ['prefix' => 'ABC'], 'request_id' => 'r-1', 'created_at' => $time];
    }

    public function test_merged_view_labels_sources_and_sorts_by_time(): void
    {
        Http::fake([
            self::GATEWAY.'/audit*' => $this->page([$this->gatewayEvent(self::A, 'key.issued')]),
            self::GATEWAY.'/projects*' => $this->page([]),
        ]);
        $this->travelTo('2026-11-26T09:00:00Z');
        Audit::record('staff.invited', actorLabel: 'cms-actor@example.test');
        $this->travelBack();

        $html = $this->actingAs(User::factory()->opsAdmin()->create())->get('/audit')->assertOk()->getContent();

        $this->assertStringContainsString('key.issued', $html);
        $this->assertStringContainsString('staff.invited', $html);
        $this->assertStringContainsString('>Gateway<', $html);
        $this->assertStringContainsString('>CMS<', $html);
        $this->assertLessThan(strpos($html, 'staff.invited'), strpos($html, 'key.issued'), 'newest (gateway 10:00) first');
    }

    public function test_project_roles_only_see_their_project_events_from_both_sources(): void
    {
        Http::fake([
            self::GATEWAY.'/audit*' => $this->page([$this->gatewayEvent(self::A, 'key.issued'), $this->gatewayEvent(self::B, 'key.revoked')]),
            self::GATEWAY.'/projects*' => $this->page([]),
        ]);
        Audit::record('staff.membership_added', projectUuid: self::A, actorLabel: 'mine@example.test');
        Audit::record('staff.membership_added', projectUuid: self::B, actorLabel: 'other@example.test');

        $this->actingAs(User::factory()->projectManager([self::A])->create())->get('/audit')->assertOk()
            ->assertSee('key.issued')->assertSee('mine@example.test')
            ->assertDontSee('key.revoked')->assertDontSee('other@example.test');

        $this->actingAs(User::factory()->projectManager([self::A])->create())->get('/audit?project_id='.self::B)->assertNotFound();
    }

    public function test_source_filter_skips_the_other_source(): void
    {
        Http::fake();
        Audit::record('staff.invited', actorLabel: 'x@example.test');

        $this->actingAs(User::factory()->owner()->create())->get('/audit?source=cms')->assertOk()->assertSee('staff.invited');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/audit'));
    }

    public function test_gateway_down_still_lists_cms_events_with_warning(): void
    {
        Http::fake([self::GATEWAY.'/*' => Http::failedConnection()]);
        Audit::record('staff.invited', actorLabel: 'x@example.test');

        $this->actingAs(User::factory()->owner()->create())->get('/audit')->assertOk()
            ->assertSee('Gateway audit events unavailable')->assertSee('staff.invited');
    }

    public function test_combined_csv_export_is_scoped_and_neutralises_formulas(): void
    {
        $gatewayCsv = "created_at,source,actor_ref,actor_name,action,target_type,target_id,project_id,metadata,request_id\n"
            .'2026-11-26T10:00:00Z,gateway,cms:a,A,key.issued,api_key,k1,'.self::A.",{},r1\n"
            .'2026-11-26T10:01:00Z,gateway,cms:a,A,key.revoked,api_key,k2,'.self::B.",{},r2\n";
        Http::fake([self::GATEWAY.'/audit*' => Http::response($gatewayCsv, 200, ['Content-Type' => 'text/csv'])]);
        Audit::record('staff.membership_added', projectUuid: self::A, actorLabel: '=HYPERLINK("evil")');
        Audit::record('staff.membership_added', projectUuid: self::B, actorLabel: 'other@example.test');

        $response = $this->actingAs(User::factory()->auditor([self::A])->create())->get('/audit/export');
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith('created_at,source,actor_ref,actor_name,action,target_type,target_id,project_id,metadata,request_id,ip_address', $csv);
        $this->assertStringContainsString('key.issued', $csv);
        $this->assertStringNotContainsString('key.revoked', $csv);
        $this->assertStringNotContainsString('other@example.test', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString(',cms,', $csv);
        $this->assertStringContainsString(',gateway,', $csv);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/audit?') && str_contains($r->url(), 'format=csv'));
        $this->assertDatabaseHas('audit_events', ['action' => 'audit.exported']);
    }

    // ------------------------------------------------------------------ settings

    private function policy(): array
    {
        $values = ['admission' => 'open', 'approved_providers' => []];
        foreach (SettingsController::POLICY_FIELDS as $fields) {
            foreach ($fields as $key => $def) {
                $values[$key] = $def['type'] === 'numeric' ? 21.88 : max(1, (int) $def['min']);
            }
        }
        $values['global_queue_limit'] = 8;
        $values['default_project_queue_limit'] = 2;

        return $values;
    }

    private function policyForm(array $overrides = []): array
    {
        return array_merge(array_diff_key($this->policy(), ['admission' => 1, 'approved_providers' => 1]), $overrides);
    }

    public function test_owner_edits_gateway_policy_and_only_changes_are_sent(): void
    {
        Http::fake([self::GATEWAY.'/settings' => fn (Request $r) => Http::response(['data' => array_merge($this->policy(), $r->method() === 'PATCH' ? $r->data() : [])])]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/settings')->assertOk()->assertSee('Save gateway settings')->assertSee('Global queued-job limit');

        $this->actingAs($owner)->patch('/settings/gateway', $this->policyForm(['global_queue_limit' => 6]))->assertRedirect('/settings');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['global_queue_limit' => 6] && $r->hasHeader('Idempotency-Key'));
    }

    public function test_gateway_422_lands_on_the_setting_field(): void
    {
        Http::fake([self::GATEWAY.'/settings' => fn (Request $r) => $r->method() === 'PATCH'
            ? $this->gatewayError(422, 'VALIDATION_FAILED', 'Invalid settings', ['default_project_queue_limit' => ['must not exceed global_queue_limit']])
            : Http::response(['data' => $this->policy()])]);

        $this->actingAs(User::factory()->owner()->create())->from('/settings')
            ->patch('/settings/gateway', $this->policyForm(['default_project_queue_limit' => 9]))
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['default_project_queue_limit' => 'must not exceed global_queue_limit']);
    }

    public function test_ops_admin_views_policy_read_only_but_edits_portal_settings(): void
    {
        Http::fake([self::GATEWAY.'/settings' => Http::response(['data' => $this->policy()])]);
        $ops = User::factory()->opsAdmin()->create();

        $this->actingAs($ops)->get('/settings')->assertOk()
            ->assertSee('Only an owner can change gateway policy settings')
            ->assertDontSee('Save gateway settings')->assertSee('Save portal settings');
        $this->actingAs($ops)->patch('/settings/gateway', $this->policyForm())->assertForbidden();
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');

        $this->actingAs($ops)->patch('/settings/portal', [
            'brand_name' => 'Unihub Staff', 'display_timezone' => 'Africa/Nairobi', 'notification_recipients' => "ops@example.test\nOnCall@Example.test",
        ])->assertRedirect('/settings');

        $settings = app(CmsSettings::class);
        $settings->flush();
        $this->assertSame('Unihub Staff', $settings->brandName());
        $this->assertSame('Africa/Nairobi', $settings->displayTimezone());
        $this->assertSame(['ops@example.test', 'oncall@example.test'], $settings->recipients());
        $this->assertDatabaseHas('audit_events', ['action' => 'settings.cms_updated', 'actor_user_id' => $ops->id]);

        $this->actingAs($ops)->get('/settings')->assertSee('<title>Settings · Unihub Staff</title>', false);
    }

    public function test_portal_settings_are_validated(): void
    {
        $owner = User::factory()->owner()->create();
        Http::fake([self::GATEWAY.'/settings' => Http::response(['data' => $this->policy()])]);

        $this->actingAs($owner)->patch('/settings/portal', ['brand_name' => '', 'display_timezone' => 'Mars/Base', 'notification_recipients' => 'not-an-email'])
            ->assertSessionHasErrorsIn('cmsSettings', ['brand_name', 'display_timezone']);
        $this->actingAs($owner)->patch('/settings/portal', ['brand_name' => 'X', 'display_timezone' => 'UTC', 'notification_recipients' => 'not-an-email'])
            ->assertSessionHasErrorsIn('cmsSettings', ['notification_recipients']);
    }

    public function test_project_roles_cannot_open_settings(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->projectManager([])->create())->get('/settings')->assertForbidden();
        $this->actingAs(User::factory()->auditor([])->create())->patch('/settings/portal', [])->assertForbidden();
    }

    public function test_display_timezone_setting_changes_rendered_times(): void
    {
        $owner = User::factory()->owner()->create();
        app(CmsSettings::class)->update(['display_timezone' => 'UTC'], $owner);
        Http::fake([self::GATEWAY.'/projects/*' => Http::response(['data' => $this->projectPayload(['id' => self::A, 'created_at' => '2026-11-26T09:00:00Z'])])]);

        $this->actingAs($owner)->get('/projects/'.self::A.'/settings')->assertSee('26 Nov 2026, 09:00');
    }
}
