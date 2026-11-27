<?php

namespace App\Console\Commands;

use App\Gateway\Exceptions\GatewayException;
use App\Gateway\GatewayClient;
use Illuminate\Console\Command;

/**
 * End-to-end check of the CMS -> gateway management connection, as the system
 * account (auditor, no projects): URL, management token, signed actor assertion
 * and the gateway's verification of it. Exit code 0 only when all of them work.
 */
class GatewayCheck extends Command
{
    protected $signature = 'cms:gateway-check';

    protected $description = 'Check that the CMS can reach and authenticate to the gateway management API';

    public function handle(GatewayClient $client): int
    {
        $actor = CreateSystemUser::find();
        if ($actor === null || $actor->isDisabled()) {
            $this->error('No active system account: run php artisan cms:system-user first.');

            return self::FAILURE;
        }

        $this->line('Gateway: '.(config('services.gateway.url') ?: '(GATEWAY_MGMT_URL not set)'));

        try {
            $health = $client->as($actor)->getData('/health', ['hours' => 1]);
        } catch (GatewayException $e) {
            $this->error('FAILED: '.$e->getMessage().($e->errorCode ? " [{$e->errorCode}]" : ''));

            return self::FAILURE;
        }

        $current = is_array($health['current'] ?? null) ? $health['current'] : [];
        $this->info('OK: the gateway accepted the CMS token and signed assertion.');
        $this->line('Telemetry: '.(($current['sampled_at'] ?? null) ? 'last sample '.$current['sampled_at'].(! empty($current['stale']) ? ' (stale)' : '') : 'no sample yet (the scheduler takes one every 30 s)'));
        $this->line('Ollama: '.($current['ollama_status'] ?? 'unknown'));

        return self::SUCCESS;
    }
}
