<?php

namespace Tests\Feature\Gateway;

use App\Gateway\Exceptions\GatewayConflictException;
use App\Gateway\Exceptions\GatewayForbiddenException;
use App\Gateway\Exceptions\GatewayNotFoundException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Gateway\Exceptions\GatewayValidationException;
use App\Gateway\GatewayClient;
use App\Models\User;
use App\Support\RequestId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GatewayClientTest extends TestCase
{
    use RefreshDatabase;

    private function client(?User $user = null): GatewayClient
    {
        return app(GatewayClient::class)->as($user ?? User::factory()->owner()->create());
    }

    public function test_get_sends_required_headers_and_no_idempotency_key(): void
    {
        Http::fake([self::GATEWAY.'/projects*' => $this->page([$this->projectPayload(['name' => 'Diary'])], 'cur-2')]);
        $user = User::factory()->owner()->create();

        $page = $this->client($user)->listProjects(['q' => 'dia', 'status' => 'active'], 'cur-1', 10);

        $this->assertSame('Diary', $page->items[0]->name);
        $this->assertSame('cur-2', $page->nextCursor);

        Http::assertSent(function (Request $request) use ($user) {
            $this->assertSame('GET', $request->method());
            $this->assertStringStartsWith(self::GATEWAY.'/projects?', $request->url());
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame(['q' => 'dia', 'status' => 'active', 'limit' => '10', 'cursor' => 'cur-1'], $query);

            $this->assertSame('Bearer '.self::MGMT_TOKEN, $request->header('Authorization')[0]);
            $this->assertSame('application/json', $request->header('Accept')[0]);
            $this->assertTrue(Str::isUuid($request->header('X-Request-Id')[0]));
            $this->assertSame(RequestId::current(), $request->header('X-Request-Id')[0]);
            $this->assertFalse($request->hasHeader('Idempotency-Key'));

            $assertion = $this->decodeAssertion($request->header('X-Actor-Assertion')[0]);
            $this->assertTrue($assertion['verified']);
            $this->assertSame($user->id, $assertion['payload']['sub']);

            return true;
        });
    }

    public function test_mutations_carry_an_idempotency_key_and_json_body(): void
    {
        $org = $this->organizationPayload(['name' => 'New Org']);
        Http::fake([self::GATEWAY.'/organizations' => Http::response(['data' => $org], 201)]);

        $key = (string) Str::uuid();
        $created = $this->client()->createOrganization('New Org', $key);
        $this->assertSame('New Org', $created->name);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->header('Idempotency-Key')[0] === $key
            && $r->isJson()
            && $r->data() === ['name' => 'New Org']
            && $r->hasHeader('X-Actor-Assertion')
            && $r->header('Authorization')[0] === 'Bearer '.self::MGMT_TOKEN);
    }

    public function test_patch_without_supplied_key_generates_a_uuid(): void
    {
        $project = $this->projectPayload();
        Http::fake([self::GATEWAY.'/projects/*' => Http::response(['data' => $project], 200)]);

        $this->client()->updateProject($project['id'], ['name' => 'Renamed']);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && Str::isUuid($r->header('Idempotency-Key')[0] ?? '')
            && $r->data() === ['name' => 'Renamed']);
    }

    public function test_each_request_gets_a_new_assertion(): void
    {
        Http::fake([self::GATEWAY.'/*' => $this->page([])]);
        $client = $this->client();
        $client->listOrganizations();
        $client->listOrganizations();

        $jtis = [];
        foreach (Http::recorded() as [$request]) {
            $jtis[] = $this->decodeAssertion($request->header('X-Actor-Assertion')[0])['payload']['jti'];
        }
        $this->assertCount(2, array_unique($jtis));
    }

    public function test_validation_error_maps_to_field_errors(): void
    {
        Http::fake([self::GATEWAY.'/projects' => $this->gatewayError(422, 'VALIDATION_FAILED', 'Invalid', [
            'slug' => ['The slug is already taken.'],
            'allowed_tasks.0' => ['Unknown task.'],
        ])]);

        try {
            $this->client()->createProject(['slug' => 'dup']);
            $this->fail('Expected GatewayValidationException');
        } catch (GatewayValidationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('VALIDATION_FAILED', $e->errorCode);
            $this->assertSame(['slug' => ['The slug is already taken.'], 'allowed_tasks' => ['Unknown task.']], $e->fieldErrors());
            $this->assertNotNull($e->requestId);
        }
    }

    public function test_error_codes_map_to_typed_exceptions(): void
    {
        $id = (string) Str::uuid();
        Http::fake([
            self::GATEWAY.'/projects/'.$id => $this->gatewayError(404, 'NOT_FOUND', 'Not found'),
            self::GATEWAY.'/organizations/*' => $this->gatewayError(403, 'FORBIDDEN', 'Forbidden'),
            self::GATEWAY.'/organizations' => $this->gatewayError(409, 'IDEMPOTENCY_CONFLICT', 'Conflict'),
            self::GATEWAY.'/projects?*' => Http::response('<html>Bad gateway</html>', 502),
        ]);
        $client = $this->client();

        $this->assertThrows(fn () => $client->getProject($id), GatewayNotFoundException::class);
        $this->assertThrows(fn () => $client->updateOrganization($id, ['status' => 'archived']), GatewayForbiddenException::class);
        $this->assertThrows(fn () => $client->createOrganization('X'), GatewayConflictException::class);
        $this->assertThrows(fn () => $client->listProjects(), GatewayUnavailableException::class);
    }

    public function test_connection_failure_is_gateway_unavailable(): void
    {
        Http::fake([self::GATEWAY.'/*' => Http::failedConnection()]);

        $this->assertThrows(fn () => $this->client()->listProjects(), GatewayUnavailableException::class);
    }

    public function test_non_envelope_success_is_rejected(): void
    {
        Http::fake([self::GATEWAY.'/projects*' => Http::response(['items' => []], 200)]);

        $this->assertThrows(fn () => $this->client()->listProjects(), GatewayUnavailableException::class);
    }

    public function test_missing_configuration_fails_closed(): void
    {
        config(['services.gateway.token' => null]);
        Http::fake();

        $this->assertThrows(fn () => $this->client()->listProjects(), GatewayUnavailableException::class);
        Http::assertNothingSent();
    }

    public function test_client_requires_an_actor(): void
    {
        $this->assertThrows(fn () => app(GatewayClient::class)->listProjects(), \LogicException::class);
    }

    public function test_timeouts_are_configured_per_contract(): void
    {
        $this->assertSame(5, config('services.gateway.connect_timeout'));
        $this->assertSame(15, config('services.gateway.timeout'));

        $reflection = new \ReflectionClass(GatewayClient::class);
        $client = app(GatewayClient::class);
        $this->assertSame(5, $reflection->getProperty('connectTimeout')->getValue($client));
        $this->assertSame(15, $reflection->getProperty('timeout')->getValue($client));
    }
}
