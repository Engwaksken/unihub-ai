<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Laravel skips CSRF checks while running tests; this swaps in a strict
 * variant so the real protection is exercised.
 */
class CsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(PreventRequestForgery::class, StrictRequestForgery::class);
    }

    /** @return array<string, array{string, string}> */
    public static function mutatingRoutes(): array
    {
        return [
            'login' => ['POST', '/login'],
            'logout' => ['POST', '/logout'],
            'create organization' => ['POST', '/organizations'],
            'create project' => ['POST', '/projects'],
            'update project' => ['PATCH', '/projects/6f1d8a8e-1111-4c4c-9a9a-000000000001'],
            'invite staff' => ['POST', '/users'],
            'accept invitation' => ['POST', '/invitations/'.str_repeat('a', 48)],
        ];
    }

    #[DataProvider('mutatingRoutes')]
    public function test_requests_without_token_are_rejected(string $method, string $uri): void
    {
        Http::fake();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->call($method, $uri, ['name' => 'x'])->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_requests_with_wrong_token_are_rejected(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->withSession(['_token' => 'right-token'])
            ->post('/organizations', ['_token' => 'wrong-token', 'name' => 'x'])
            ->assertStatus(419);
    }

    public function test_requests_with_session_token_pass(): void
    {
        Http::fake([self::GATEWAY.'/organizations' => Http::response(['data' => $this->organizationPayload(['name' => 'Ok'])], 201)]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->withSession(['_token' => 'right-token'])
            ->post('/organizations', ['_token' => 'right-token', 'name' => 'Ok'])
            ->assertRedirect('/organizations');
    }

    public function test_forms_render_a_csrf_field(): void
    {
        $this->get('/login')->assertSee('name="_token"', false);

        Http::fake([self::GATEWAY.'/organizations*' => $this->page([])]);
        $this->actingAs(User::factory()->owner()->create())
            ->get('/organizations')->assertSee('name="_token"', false);
    }
}

class StrictRequestForgery extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}
