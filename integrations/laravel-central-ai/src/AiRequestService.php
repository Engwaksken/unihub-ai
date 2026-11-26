<?php

namespace Unihub\CentralAi;

use Illuminate\Support\Str;
use Unihub\CentralAi\Exceptions\CentralAiException;

/**
 * Orchestrates submit → poll for a user's request, enforcing local ownership and
 * the PRD §06/§09 client rules:
 *  - one idempotency key per logical request, reused on every retry;
 *  - 429/503/network → keep the request 'pending' and retry after Retry-After;
 *  - poll at most every min_poll_interval seconds, give up after client_deadline;
 *  - never forward gateway internals to the app.
 */
class AiRequestService
{
    public function __construct(
        private readonly CentralAiClient $client,
        private readonly int $minPollInterval = 3,
        private readonly int $clientDeadline = 300,
    ) {}

    public function create(int|string $userId, string $task, string $input, int $maxTokens = 200): AiRequest
    {
        $request = AiRequest::create([
            'user_id' => $userId,
            'task' => $task,
            'input' => $input,
            'max_tokens' => $maxTokens,
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'pending',
        ]);
        $this->trySubmit($request);

        return $request->refresh();
    }

    /** Ownership-checked lookup; returns null for other users' requests (treat as 404). */
    public function findForUser(string $id, int|string $userId): ?AiRequest
    {
        return AiRequest::where('id', $id)->where('user_id', $userId)->first();
    }

    /** Advance the request if due (submit retry or poll). Safe to call on every app poll. */
    public function refresh(AiRequest $request): AiRequest
    {
        if ($request->isTerminal()) {
            return $request;
        }
        if ($request->created_at->diffInSeconds(now()) > $this->clientDeadline) {
            $request->update(['status' => 'expired', 'error_code' => 'CLIENT_DEADLINE']);

            return $request;
        }
        if ($request->gateway_job_id === null) {
            if ($request->retry_at === null || $request->retry_at->isPast()) {
                $this->trySubmit($request);
            }

            return $request->refresh();
        }
        if ($request->next_poll_at !== null && $request->next_poll_at->isFuture()) {
            return $request;
        }

        try {
            $job = $this->client->get($request->gateway_job_id);
        } catch (CentralAiException $e) {
            $request->update(['next_poll_at' => now()->addSeconds(max($this->minPollInterval, $e->retryAfter ?? 10))]);

            return $request;
        }

        $update = ['status' => $job['status'], 'next_poll_at' => now()->addSeconds($this->minPollInterval)];
        if ($job['status'] === 'completed') {
            $update['output'] = $job['output'] ?? null;
        }
        if (in_array($job['status'], ['failed', 'expired'], true)) {
            $update['error_code'] = $job['error']['code'] ?? 'FAILED';
        }
        $request->update($update);

        return $request;
    }

    public function cancel(AiRequest $request): AiRequest
    {
        if ($request->gateway_job_id !== null && in_array($request->status, ['pending', 'queued'], true)) {
            try {
                $this->client->cancel($request->gateway_job_id);
            } catch (CentralAiException $e) {
                if ($e->status === 409) { // already running/finished: leave it to normal polling
                    return $request;
                }
                throw $e;
            }
        }
        if (! $request->isTerminal()) {
            $request->update(['status' => 'cancelled']);
        }

        return $request;
    }

    private function trySubmit(AiRequest $request): void
    {
        try {
            $r = $this->client->submit($request->task, (string) $request->input, $request->idempotency_key, (int) $request->max_tokens);
            $request->update([
                'gateway_job_id' => $r['job_id'],
                'status' => $r['status'],
                'retry_at' => null,
                'error_code' => null,
                'next_poll_at' => now()->addSeconds($this->minPollInterval),
            ]);
        } catch (CentralAiException $e) {
            if ($e->retryable()) {
                $request->update(['status' => 'pending', 'error_code' => $e->errorCode, 'retry_at' => now()->addSeconds(max(1, $e->retryAfter ?? 15))]);
            } else {
                $request->update(['status' => 'failed', 'error_code' => $e->errorCode]);
            }
        }
    }

    /** Shape returned to the mobile app (no gateway ids, no internals). */
    public static function present(AiRequest $r): array
    {
        $retryable = $r->status === 'pending' && $r->retry_at !== null;

        return [
            'id' => $r->id,
            'status' => $r->status,
            'output' => $r->status === 'completed' ? $r->output : null,
            'message' => match ($r->status) {
                'pending' => $retryable ? 'The assistant is busy; we will retry automatically.' : 'Sending…',
                'queued' => 'Waiting for the assistant…',
                'running' => 'The assistant is writing…',
                'failed' => 'The assistant could not complete this request.',
                'expired' => 'This request took too long. Please try again.',
                'cancelled' => 'Cancelled.',
                default => null,
            },
            'poll_after_seconds' => $r->isTerminal() ? null : 3,
            'created_at' => $r->created_at->toIso8601String(),
        ];
    }
}
