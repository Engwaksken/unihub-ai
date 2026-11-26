<?php

namespace Unihub\CentralAi\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use Unihub\CentralAi\AiRequest;
use Unihub\CentralAi\AiRequestService;
use Unihub\CentralAi\CentralAiClient;
use Unihub\CentralAi\CentralAiServiceProvider;
use Unihub\CentralAi\Exceptions\CentralAiException;

class AiRequestServiceTest extends TestCase
{
    private const KEY = 'uhk_test_abcdefghjk_0123456789012345678901234567890123456789012';

    protected function getPackageProviders($app): array
    {
        return [CentralAiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('central-ai.url', 'https://ai.example.test');
        $app['config']->set('central-ai.key', self::KEY);
        $app['config']->set('central-ai.min_poll_interval', 0);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    private function service(): AiRequestService
    {
        return $this->app->make(AiRequestService::class);
    }

    public function test_submit_sends_contract_fields_and_bearer_key(): void
    {
        Http::fake(['ai.example.test/v1/jobs' => Http::response(['job_id' => (string) Str::uuid(), 'status' => 'queued', 'request_id' => (string) Str::uuid()], 202)]);

        $r = $this->service()->create(7, 'daily_plan', 'Plan my study day');

        $this->assertSame('queued', $r->status);
        Http::assertSent(fn (Request $req) => $req->hasHeader('Authorization', 'Bearer '.self::KEY)
            && $req['task'] === 'daily_plan' && $req['input'] === 'Plan my study day'
            && $req['max_tokens'] === 200 && Str::isUuid($req['idempotency_key']));
    }

    public function test_full_lifecycle_and_presented_shape_hides_gateway_ids(): void
    {
        $jobId = (string) Str::uuid();
        Http::fakeSequence()
            ->push(['job_id' => $jobId, 'status' => 'queued', 'request_id' => 'r'], 202)
            ->push(['job_id' => $jobId, 'status' => 'running'], 200)
            ->push(['job_id' => $jobId, 'status' => 'completed', 'output' => '08:00 Read'], 200);

        $svc = $this->service();
        $r = $svc->create(7, 'daily_plan', 'Plan');
        $this->assertSame('running', $svc->refresh($r)->status);
        $r = $svc->refresh($r);
        $this->assertSame('completed', $r->status);

        $shown = AiRequestService::present($r);
        $this->assertSame('08:00 Read', $shown['output']);
        $this->assertNull($shown['poll_after_seconds']);
        $this->assertStringNotContainsString($jobId, json_encode($shown));
        $this->assertStringNotContainsString($jobId, $r->toJson());
        $this->assertStringNotContainsString('08:00', (string) AiRequest::query()->toBase()->value('output')); // encrypted at rest
    }

    public function test_429_keeps_request_pending_and_retries_with_same_idempotency_key(): void
    {
        $keys = [];
        $calls = 0;
        Http::fake(function (Request $req) use (&$keys, &$calls) {
            $keys[] = $req['idempotency_key'];
            $calls++;

            return $calls === 1
                ? Http::response(['error' => ['code' => 'QUEUE_FULL', 'message' => 'Try later'], 'request_id' => 'x'], 429, ['Retry-After' => '1'])
                : Http::response(['job_id' => (string) Str::uuid(), 'status' => 'queued', 'request_id' => 'y'], 202);
        });

        $svc = $this->service();
        $r = $svc->create(7, 'daily_plan', 'Plan');
        $this->assertSame('pending', $r->status);
        $this->assertSame('QUEUE_FULL', $r->error_code);
        $this->assertStringContainsString('busy', AiRequestService::present($r)['message']);

        $this->travel(2)->seconds();
        $r = $svc->refresh($r);
        $this->assertSame('queued', $r->status);
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
    }

    public function test_network_failure_is_retryable_and_validation_is_not(): void
    {
        $networkDown = true;
        Http::fake(function () use (&$networkDown) {
            if ($networkDown) {
                throw new ConnectionException('down');
            }

            return Http::response(['error' => ['code' => 'TOKEN_BUDGET', 'message' => 'too long'], 'request_id' => 'z'], 422);
        });
        $r = $this->service()->create(7, 'daily_plan', 'Plan');
        $this->assertSame('pending', $r->status);
        $this->assertSame('NETWORK', $r->error_code);

        $networkDown = false;
        $r2 = $this->service()->create(7, 'daily_plan', str_repeat('x', 100));
        $this->assertSame('failed', $r2->status);
        $this->assertSame('TOKEN_BUDGET', $r2->error_code);
    }

    public function test_ownership_is_enforced(): void
    {
        Http::fake(['*' => Http::response(['job_id' => (string) Str::uuid(), 'status' => 'queued', 'request_id' => 'r'], 202)]);
        $r = $this->service()->create(7, 'daily_plan', 'Plan');
        $this->assertNotNull($this->service()->findForUser($r->id, 7));
        $this->assertNull($this->service()->findForUser($r->id, 8));
    }

    public function test_client_deadline_expires_request(): void
    {
        Http::fake(['*' => Http::response(['job_id' => (string) Str::uuid(), 'status' => 'queued', 'request_id' => 'r'], 202)]);
        $r = $this->service()->create(7, 'daily_plan', 'Plan');
        $this->travel(301)->seconds();
        $this->assertSame('expired', $this->service()->refresh($r)->status);
    }

    public function test_cancel_running_job_conflict_is_left_to_polling(): void
    {
        $jobId = (string) Str::uuid();
        Http::fakeSequence()
            ->push(['job_id' => $jobId, 'status' => 'queued', 'request_id' => 'r'], 202)
            ->push(['error' => ['code' => 'INVALID_STATE', 'message' => 'running'], 'request_id' => 'r'], 409);
        $svc = $this->service();
        $r = $svc->create(7, 'daily_plan', 'Plan');
        $this->assertSame('queued', $svc->cancel($r)->status);
    }

    public function test_client_rejects_bad_configuration_and_maps_errors(): void
    {
        try {
            new CentralAiClient('http://ai.example.test', self::KEY);
            $this->fail('plain http must be rejected');
        } catch (\InvalidArgumentException) {
        }
        try {
            new CentralAiClient('https://ai.example.test', '');
            $this->fail('missing key must be rejected');
        } catch (\InvalidArgumentException) {
        }

        Http::fake(['*' => Http::response(['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found'], 'request_id' => 'abc'], 404)]);
        try {
            $this->app->make(CentralAiClient::class)->get((string) Str::uuid());
            $this->fail('404 must throw');
        } catch (CentralAiException $e) {
            $this->assertSame('NOT_FOUND', $e->errorCode);
            $this->assertSame('abc', $e->requestId);
            $this->assertFalse($e->retryable());
        }
    }
}
