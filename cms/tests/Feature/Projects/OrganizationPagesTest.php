<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrganizationPagesTest extends TestCase
{
    use RefreshDatabase;

    private const ORG = '6f1d8a8e-1111-4c4c-9a9a-0000000000f1';

    public function test_owner_lists_organizations_with_management_controls(): void
    {
        Http::fake([self::GATEWAY.'/organizations*' => $this->page([
            $this->organizationPayload(['id' => self::ORG, 'name' => 'Unihub Solutions', 'project_count' => 3]),
            $this->organizationPayload(['name' => 'Old Org', 'status' => 'archived']),
        ])]);

        $this->actingAs(User::factory()->owner()->create())
            ->get('/organizations?q=uni')
            ->assertOk()
            ->assertSee('Unihub Solutions')->assertSee('Old Org')
            ->assertSee('Create organization')->assertSee('Rename')->assertSee('Archive')->assertSee('Restore');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'q=uni'));
    }

    public function test_ops_admin_can_view_but_not_manage(): void
    {
        Http::fake([self::GATEWAY.'/organizations*' => $this->page([$this->organizationPayload(['name' => 'Unihub Solutions'])])]);
        $ops = User::factory()->opsAdmin()->create();

        $this->actingAs($ops)->get('/organizations')->assertOk()->assertSee('Unihub Solutions')->assertDontSee('Create organization');
        $this->actingAs($ops)->post('/organizations', ['name' => 'X'])->assertForbidden();
        $this->actingAs($ops)->patch('/organizations/'.self::ORG.'/status', ['status' => 'archived'])->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_project_scoped_roles_cannot_see_organizations(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->projectManager([])->create())->get('/organizations')->assertForbidden();
        $this->actingAs(User::factory()->auditor([])->create())->get('/organizations')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_owner_creates_renames_and_archives(): void
    {
        Http::fake([
            self::GATEWAY.'/organizations' => Http::response(['data' => $this->organizationPayload(['id' => self::ORG, 'name' => 'New Org'])], 201),
            self::GATEWAY.'/organizations/*' => fn (Request $r) => Http::response(['data' => $this->organizationPayload(array_merge(['id' => self::ORG, 'name' => 'New Org'], $r->data()))]),
        ]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/organizations', ['name' => '  New Org '])->assertRedirect('/organizations')->assertSessionHas('status');
        $this->actingAs($owner)->patch('/organizations/'.self::ORG, ['name' => 'Renamed Org'])->assertRedirect('/organizations');
        $this->actingAs($owner)->patch('/organizations/'.self::ORG.'/status', ['status' => 'archived'])->assertRedirect('/organizations');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['name' => 'New Org'] && $r->hasHeader('Idempotency-Key'));
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['name' => 'Renamed Org']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['status' => 'archived']);
    }

    public function test_gateway_validation_error_surfaces(): void
    {
        Http::fake([self::GATEWAY.'/organizations' => $this->gatewayError(422, 'VALIDATION_FAILED', 'Invalid', ['name' => ['Name already exists.']])]);

        $this->actingAs(User::factory()->owner()->create())
            ->from('/organizations')
            ->post('/organizations', ['name' => 'Dup'])
            ->assertRedirect('/organizations')
            ->assertSessionHasErrors(['name' => 'Name already exists.']);
    }

    public function test_unreachable_gateway_shows_error_state(): void
    {
        Http::fake([self::GATEWAY.'/*' => Http::failedConnection()]);

        $this->actingAs(User::factory()->owner()->create())
            ->get('/organizations')->assertOk()->assertSee('Gateway unreachable');
    }

    public function test_empty_state(): void
    {
        Http::fake([self::GATEWAY.'/organizations*' => $this->page([])]);

        $this->actingAs(User::factory()->owner()->create())
            ->get('/organizations')->assertOk()->assertSee('No organizations found');
    }
}
