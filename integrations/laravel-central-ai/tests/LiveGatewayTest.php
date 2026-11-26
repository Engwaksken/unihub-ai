<?php

namespace Unihub\CentralAi\Tests;

use Orchestra\Testbench\TestCase;
use Unihub\CentralAi\AiRequestService;
use Unihub\CentralAi\CentralAiServiceProvider;

/**
 * Opt-in end-to-end test against a REAL gateway (staging or local), e.g.
 *   CENTRAL_AI_LIVE_URL=http://127.0.0.1:8787 CENTRAL_AI_LIVE_KEY=uhk_test_... vendor/bin/phpunit --filter LiveGateway
 * Requires a running worker. Skipped when the env vars are absent.
 */
class LiveGatewayTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [CentralAiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('central-ai.url', (string) getenv('CENTRAL_AI_LIVE_URL'));
        $app['config']->set('central-ai.key', (string) getenv('CENTRAL_AI_LIVE_KEY'));
        $app['config']->set('central-ai.min_poll_interval', 1);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    public function test_submit_and_poll_until_completed(): void
    {
        if (! getenv('CENTRAL_AI_LIVE_URL') || ! getenv('CENTRAL_AI_LIVE_KEY')) {
            $this->markTestSkipped('Set CENTRAL_AI_LIVE_URL and CENTRAL_AI_LIVE_KEY to run against a real gateway.');
        }
        $svc = $this->app->make(AiRequestService::class);
        $r = $svc->create(1, 'daily_plan', 'Plan my study day (live integration test)');
        $this->assertContains($r->status, ['queued', 'pending'], 'error: '.$r->error_code);

        $deadline = time() + 180;
        while (! $r->isTerminal() && time() < $deadline) {
            sleep(1);
            $r = $svc->refresh($r);
        }
        $this->assertSame('completed', $r->status, 'final error: '.$r->error_code);
        $this->assertNotEmpty($r->output);
        fwrite(STDERR, "\nlive output: ".mb_substr((string) $r->output, 0, 120)."\n");
    }
}
