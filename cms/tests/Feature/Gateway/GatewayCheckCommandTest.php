<?php

namespace Tests\Feature\Gateway;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GatewayCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fails_without_system_account(): void
    {
        Http::fake();
        $this->artisan('cms:gateway-check')->expectsOutputToContain('cms:system-user')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_reports_ok_with_telemetry(): void
    {
        $this->artisan('cms:system-user')->assertSuccessful();
        Http::fake([self::GATEWAY.'/health*' => Http::response(['data' => ['current' => [
            'sampled_at' => '2026-11-27T10:00:00Z', 'stale' => false, 'ollama_status' => 'idle',
        ]]])]);

        $this->artisan('cms:gateway-check')
            ->expectsOutputToContain('OK: the gateway accepted')
            ->expectsOutputToContain('Ollama: idle')
            ->assertSuccessful();
    }

    public function test_reports_rejected_token_as_failure(): void
    {
        $this->artisan('cms:system-user')->assertSuccessful();
        Http::fake([self::GATEWAY.'/*' => $this->gatewayError(401, 'UNAUTHENTICATED', 'Invalid management token')]);

        $this->artisan('cms:gateway-check')->expectsOutputToContain('FAILED')->assertFailed();
    }

    public function test_reports_unreachable_gateway_as_failure(): void
    {
        $this->artisan('cms:system-user')->assertSuccessful();
        Http::fake([self::GATEWAY.'/*' => Http::failedConnection()]);

        $this->artisan('cms:gateway-check')->expectsOutputToContain('FAILED')->assertFailed();
    }
}
