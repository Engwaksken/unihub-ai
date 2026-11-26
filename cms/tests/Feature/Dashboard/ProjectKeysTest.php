<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProjectKeysTest extends TestCase
{
    use RefreshDatabase;

    private const A = '6f1d8a8e-1111-4c4c-9a9a-0000000002a1';

    private const B = '6f1d8a8e-1111-4c4c-9a9a-0000000002b2';

    private const KEY = '6f1d8a8e-1111-4c4c-9a9a-0000000002c3';

    private const SECRET = 'uhk_live_ABCDEFGHJK_c2VjcmV0LXNlY3JldC1zZWNyZXQtc2VjcmV0LXNlY3JldA';

    private function key(array $overrides = []): array
    {
        return array_merge([
            'id' => self::KEY, 'project_id' => self::A, 'label' => 'backend', 'environment' => 'live',
            'prefix' => 'ABCDEFGHJK', 'status' => 'active', 'rotated_from_id' => null, 'created_by' => 'cms:x',
            'created_at' => '2026-11-26T09:00:00Z', 'expires_at' => null, 'revoked_at' => null, 'last_used_at' => null,
        ], $overrides);
    }

    private function fakeGateway(): void
    {
        Http::fake([
            self::GATEWAY.'/projects/*/keys?*' => $this->page([$this->key()]),
            self::GATEWAY.'/projects/*/keys' => Http::response(['data' => $this->key(['id' => '6f1d8a8e-1111-4c4c-9a9a-0000000002d4', 'prefix' => 'NEWPREFIX1']), 'secret' => self::SECRET], 201, ['Cache-Control' => 'no-store']),
            self::GATEWAY.'/keys/*/revoke' => Http::response(['data' => $this->key(['status' => 'revoked'])]),
            self::GATEWAY.'/projects/*' => fn (Request $r) => Http::response(['data' => $this->projectPayload(['id' => str_contains($r->url(), self::B) ? self::B : self::A, 'name' => 'Proj'])]),
        ]);
    }

    public function test_owner_lists_keys_with_metadata_only(): void
    {
        $this->fakeGateway();

        $this->actingAs(User::factory()->owner()->create())->get('/projects/'.self::A.'/keys')->assertOk()
            ->assertSee('ABCDEFGHJK')->assertSee('backend')->assertSee('Never used')
            ->assertSee('Issue key')->assertSee('Rotate')->assertSee('Revoke')
            ->assertDontSee(self::SECRET);
    }

    public function test_issued_secret_is_shown_once_with_no_store_and_never_persisted(): void
    {
        $this->fakeGateway();
        $owner = User::factory()->owner()->create();
        $idem = '11111111-2222-4333-8444-000000000001';

        $response = $this->actingAs($owner)->post('/projects/'.self::A.'/keys', ['_idempotency_key' => $idem, 'label' => 'mobile backend']);

        $response->assertOk()->assertSee(self::SECRET)->assertSee('only time the secret is shown');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertSessionMissing('secret');
        foreach (session()->all() as $value) {
            $this->assertStringNotContainsString(self::SECRET, json_encode($value));
        }

        // Nothing in the CMS database contains the secret (audit, sessions, cache...).
        foreach (['audit_events', 'sessions', 'cache', 'cms_settings'] as $table) {
            $this->assertStringNotContainsString(self::SECRET, json_encode(DB::table($table)->get()));
        }

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/projects/'.self::A.'/keys')
            && $r->data() === ['label' => 'mobile backend'] && $r->header('Idempotency-Key')[0] === $idem);

        // The keys page afterwards shows metadata only.
        $this->actingAs($owner)->get('/projects/'.self::A.'/keys')->assertOk()->assertDontSee(self::SECRET);
    }

    public function test_resubmitting_the_issue_form_never_reveals_the_secret_again(): void
    {
        Http::fake([
            self::GATEWAY.'/projects/*/keys' => $this->gatewayError(409, 'IDEMPOTENCY_REPLAY_UNAVAILABLE', 'Key issuance cannot be replayed'),
            self::GATEWAY.'/projects/*' => Http::response(['data' => $this->projectPayload(['id' => self::A])]),
        ]);

        $this->actingAs(User::factory()->owner()->create())
            ->from('/projects/'.self::A.'/keys')
            ->post('/projects/'.self::A.'/keys', ['_idempotency_key' => '11111111-2222-4333-8444-000000000001'])
            ->assertRedirect('/projects/'.self::A.'/keys')
            ->assertSessionHasErrors(['form' => 'This key was already issued by an earlier submission of the form. For security its secret cannot be shown again; revoke it and issue a new key if the secret was not saved.']);
    }

    public function test_rotate_sends_rotates_key_id_and_overlap(): void
    {
        $this->fakeGateway();

        $this->actingAs(User::factory()->owner()->create())
            ->post('/projects/'.self::A.'/keys', ['rotates_key_id' => self::KEY, 'overlap_days' => 3, 'label' => 'backend'])
            ->assertOk()->assertSee('Key rotated')->assertSee(self::SECRET);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->data() === ['label' => 'backend', 'rotates_key_id' => self::KEY, 'overlap_days' => 3]);
    }

    public function test_overlap_is_limited_to_30_days(): void
    {
        $this->fakeGateway();

        $this->actingAs(User::factory()->owner()->create())
            ->post('/projects/'.self::A.'/keys', ['rotates_key_id' => self::KEY, 'overlap_days' => 31])
            ->assertSessionHasErrors('overlap_days');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_revoke_is_behind_a_confirmation_dialog_and_posts_to_gateway(): void
    {
        $this->fakeGateway();
        $owner = User::factory()->owner()->create();

        $html = $this->actingAs($owner)->get('/projects/'.self::A.'/keys')->getContent();
        $this->assertMatchesRegularExpression('#<dialog[^>]*>\s*<form method="POST" action="[^"]+/keys/'.self::KEY.'/revoke"#', $html);
        $this->assertStringContainsString('cannot be undone', $html);

        $this->actingAs($owner)->post('/projects/'.self::A.'/keys/'.self::KEY.'/revoke')
            ->assertRedirect('/projects/'.self::A.'/keys');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/keys/'.self::KEY.'/revoke') && $r->hasHeader('Idempotency-Key'));
    }

    public function test_ops_admin_and_auditor_can_view_but_not_manage_keys(): void
    {
        $this->fakeGateway();

        foreach ([User::factory()->opsAdmin()->create(), User::factory()->auditor([self::A])->create()] as $user) {
            $this->actingAs($user)->get('/projects/'.self::A.'/keys')->assertOk()
                ->assertSee('ABCDEFGHJK')->assertDontSee('Issue key')->assertDontSee('Rotate');
            $this->actingAs($user)->post('/projects/'.self::A.'/keys', ['label' => 'x'])->assertForbidden();
            $this->actingAs($user)->post('/projects/'.self::A.'/keys/'.self::KEY.'/revoke')->assertForbidden();
        }
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_mixed_role_user_is_evaluated_per_project(): void
    {
        $this->fakeGateway();
        // Project manager on A, auditor on B.
        $user = User::factory()->withTwoFactor()->withRoles([Role::ProjectManager, Role::Auditor])
            ->withMemberships([self::A => Role::ProjectManager, self::B => Role::Auditor])->create();

        $this->actingAs($user)->get('/projects/'.self::A.'/keys')->assertOk()->assertSee('Issue key');
        $this->actingAs($user)->get('/projects/'.self::B.'/keys')->assertOk()->assertDontSee('Issue key');
        $this->actingAs($user)->post('/projects/'.self::B.'/keys', ['label' => 'x'])->assertForbidden();
        $this->actingAs($user)->post('/projects/'.self::A.'/keys', ['label' => 'x'])->assertOk()->assertSee(self::SECRET);
    }

    public function test_project_manager_gets_404_on_unassigned_project(): void
    {
        Http::fake();
        $pm = User::factory()->projectManager([self::A])->create();

        $this->actingAs($pm)->get('/projects/'.self::B.'/keys')->assertNotFound();
        $this->actingAs($pm)->post('/projects/'.self::B.'/keys')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_global_keys_page_lists_keys_of_visible_projects_only(): void
    {
        Http::fake([
            self::GATEWAY.'/projects/*/keys*' => fn (Request $r) => $this->page([$this->key(['prefix' => str_contains($r->url(), self::A) ? 'PREFIXAAAA' : 'PREFIXBBBB'])]),
            self::GATEWAY.'/projects?*' => $this->page([
                $this->projectPayload(['id' => self::A, 'name' => 'Alpha']),
                $this->projectPayload(['id' => self::B, 'name' => 'Beta']),
            ]),
        ]);

        $this->actingAs(User::factory()->auditor([self::A])->create())->get('/keys')->assertOk()
            ->assertSee('Alpha')->assertSee('PREFIXAAAA')
            ->assertDontSee('Beta')->assertDontSee('PREFIXBBBB');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), self::B.'/keys'));
    }
}
