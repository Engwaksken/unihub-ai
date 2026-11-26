<?php

namespace Unihub\CentralAi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Unihub\CentralAi\Exceptions\CentralAiException;

/**
 * Thin client for the gateway public API (docs/api/public-openapi.yaml).
 * Submit, poll and cancel jobs. It never retries on its own; callers retry later
 * with the same idempotency key, which the gateway deduplicates.
 */
class CentralAiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $key,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 15,
    ) {
        if ($key === '' || ! str_starts_with($key, 'uhk_')) {
            throw new \InvalidArgumentException('AI_API_KEY is missing or malformed.');
        }
        if (! str_starts_with($baseUrl, 'https://') && ! preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?#', $baseUrl)) {
            throw new \InvalidArgumentException('AI_BASE_URL must use HTTPS.');
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->key)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout);
    }

    /** @return array{job_id: string, status: string, request_id: string} */
    public function submit(string $task, string $input, string $idempotencyKey, int $maxTokens = 200): array
    {
        return $this->send(fn () => $this->http()->post('/v1/jobs', [
            'task' => $task,
            'input' => $input,
            'max_tokens' => $maxTokens,
            'idempotency_key' => $idempotencyKey,
        ]), 202);
    }

    /** @return array<string, mixed> status, output (completed), error (failed/expired), model, timings */
    public function get(string $jobId): array
    {
        return $this->send(fn () => $this->http()->get('/v1/jobs/'.rawurlencode($jobId)), 200);
    }

    public function cancel(string $jobId): array
    {
        return $this->send(fn () => $this->http()->delete('/v1/jobs/'.rawurlencode($jobId)), 200);
    }

    private function send(callable $call, int $expected): array
    {
        try {
            /** @var Response $r */
            $r = $call();
        } catch (ConnectionException) {
            throw new CentralAiException('NETWORK', 'Gateway not reachable', 0);
        }
        if ($r->status() === $expected) {
            return $r->json();
        }
        $retryAfter = $r->header('Retry-After');

        throw new CentralAiException(
            (string) ($r->json('error.code') ?? 'HTTP_'.$r->status()),
            (string) ($r->json('error.message') ?? 'Gateway error'),
            $r->status(),
            is_numeric($retryAfter) ? (int) $retryAfter : null,
            $r->json('request_id'),
        );
    }
}
