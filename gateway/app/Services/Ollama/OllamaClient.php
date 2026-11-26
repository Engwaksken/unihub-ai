<?php

namespace App\Services\Ollama;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Local Ollama client (ADR D8). The endpoint is fixed configuration and must be
 * loopback — never derived from a request (SSRF control). No pulls, no tools.
 */
class OllamaClient
{
    private const LOOPBACK = ['127.0.0.1', 'localhost', '::1', '[::1]'];

    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $baseUrl ??= (string) config('gateway.ollama.url');
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (! in_array($host, self::LOOPBACK, true) || parse_url($baseUrl, PHP_URL_SCHEME) !== 'http') {
            throw new RuntimeException('Ollama URL must be http on loopback.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    private function http(int $timeout): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->connectTimeout((int) config('gateway.ollama.connect_timeout'))
            ->timeout($timeout)
            ->withoutRedirecting();
    }

    /** @return array{content: string, tokens_in: ?int, tokens_out: ?int} */
    public function chat(string $model, string $system, string $input, int $numPredict, string $format, int $deadlineSeconds): array
    {
        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $input],
            ],
            'stream' => false,
            'keep_alive' => config('gateway.ollama.keep_alive'),
            'options' => [
                'num_ctx' => (int) config('gateway.ollama.num_ctx'),
                'num_predict' => $numPredict,
                'num_thread' => (int) config('gateway.ollama.num_thread'),
            ],
        ];
        if ($format === 'json') {
            $body['format'] = 'json';
        }

        try {
            $response = $this->http($deadlineSeconds)->post('/api/chat', $body);
        } catch (ConnectionException $e) {
            if (str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false) {
                // Not retried: a prompt that hit the deadline will very likely hit it again.
                throw new InferenceException('INFERENCE_TIMEOUT', 'Inference deadline exceeded', false);
            }
            throw new InferenceException('INFERENCE_FAILED', 'Inference service unavailable', true);
        }

        if ($response->serverError()) {
            throw new InferenceException('INFERENCE_FAILED', 'Inference service error', true);
        }
        if (! $response->successful()) {
            throw new InferenceException('INFERENCE_FAILED', 'Inference request rejected', false);
        }

        $content = $response->json('message.content');
        if (! is_string($content)) {
            throw new InferenceException('INFERENCE_FAILED', 'Malformed inference response', false);
        }

        return [
            'content' => $content,
            'tokens_in' => is_int($response->json('prompt_eval_count')) ? $response->json('prompt_eval_count') : null,
            'tokens_out' => is_int($response->json('eval_count')) ? $response->json('eval_count') : null,
        ];
    }

    /** Installed models from /api/tags. */
    public function tags(): array
    {
        return $this->http(10)->get('/api/tags')->throw()->json('models') ?? [];
    }

    /** Loaded models from /api/ps; null when unreachable. */
    public function ps(): ?array
    {
        try {
            $response = $this->http(5)->get('/api/ps');

            return $response->successful() ? ($response->json('models') ?? []) : null;
        } catch (ConnectionException) {
            return null;
        }
    }
}
