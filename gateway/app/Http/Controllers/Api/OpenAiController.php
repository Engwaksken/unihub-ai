<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\ApiKey;
use App\Models\Job;
use App\Services\AdmissionService;
use App\Services\JobWaiter;
use App\Services\PayloadCipher;
use App\Services\Settings;
use App\Support\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * OpenAI-compatible endpoints (audit G3, phase C) for apps built against
 * /v1/chat/completions, such as Elevate Her ("Custom (OpenAI-compatible)").
 *
 * Every request still goes through the gateway's own pipeline: project key, task
 * allowlist (the reserved task key `chat`, whose published system template is always
 * sent first), quotas, the single inference worker and usage accounting. The request
 * waits for the worker's answer (sync_wait_seconds) instead of returning a job id.
 * Errors keep the gateway envelope, whose `error.message` is where OpenAI clients look.
 */
class OpenAiController extends Controller
{
    public const CHAT_TASK = 'chat';

    private const ROLES = ['system', 'user', 'assistant'];

    public function models(): JsonResponse
    {
        $data = AiModel::where('enabled', true)->where('installed', true)->orderBy('tag')->get()
            ->map(fn (AiModel $m) => [
                'id' => $m->tag,
                'object' => 'model',
                'created' => $m->created_at?->getTimestamp() ?? 0,
                'owned_by' => 'witu-ai',
            ])->values()->all();

        return new JsonResponse(['object' => 'list', 'data' => $data]);
    }

    public function chatCompletions(Request $request, AdmissionService $admission, Settings $settings, PayloadCipher $cipher, JobWaiter $waiter): JsonResponse
    {
        $data = $request->json()->all();
        $v = Validator::make($data, [
            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'messages' => ['required', 'array', 'min:1', 'max:64'],
            'messages.*.role' => ['required', 'string'],
            'messages.*.content' => ['present'],
            'max_tokens' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_completion_tokens' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'temperature' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2'],
            'n' => ['sometimes', 'nullable', 'integer', 'in:1'],
            'stream' => ['sometimes', 'nullable', 'boolean'],
            'response_format' => ['sometimes', 'nullable', 'array'],
            'response_format.type' => ['required_with:response_format', 'string', 'in:text,json_object,json_schema'],
        ]);
        if ($v->fails()) {
            throw ApiException::validation('Invalid request', $v->errors()->toArray());
        }
        if (! empty($data['stream'])) {
            throw ApiException::validation('Streaming is not supported; send "stream": false', ['stream' => ['not supported']]);
        }

        $messages = $this->messages($data['messages']);
        // JSON mode (json_object, or json_schema without enforcing the schema) makes Ollama
        // emit one JSON value and stop: valid output for apps that parse it, and far fewer
        // tokens than a small model's prose or code fences.
        $json = in_array($data['response_format']['type'] ?? null, ['json_object', 'json_schema'], true);
        $input = json_encode(array_filter([
            'messages' => $messages,
            'temperature' => isset($data['temperature']) ? (float) $data['temperature'] : null,
            'format' => $json ? 'json' : null,
        ], fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (mb_strlen($input) > 32000) {
            throw ApiException::validation('Conversation too long', ['messages' => ['at most 32000 characters in total']]);
        }

        $requested = $data['max_completion_tokens'] ?? $data['max_tokens'] ?? null;
        [$job] = $admission->submit($this->key($request), [
            'task' => self::CHAT_TASK,
            'input' => $input,
            'input_kind' => Job::INPUT_CHAT,
            'model' => $data['model'] ?? null,
            'estimate_text' => implode("\n", array_column($messages, 'content')),
            'max_tokens' => (int) min($requested ?? PHP_INT_MAX, $settings->int('max_output_tokens')),
            // OpenAI clients send no idempotency key; honour the header when one is given.
            'idempotency_key' => $this->idempotencyKey($request),
        ]);

        $job = $waiter->await($job, $settings->int('sync_wait_seconds'));

        return match ($job->state) {
            Job::COMPLETED => $this->completion($job, $cipher),
            Job::QUEUED, Job::RUNNING => throw new ApiException('TIMEOUT', 504,
                'The model did not answer within '.$settings->int('sync_wait_seconds').' seconds; the request stays queued as job '.$job->id, ['job_id' => $job->id], 30),
            Job::EXPIRED => throw new ApiException('QUEUE_TIMEOUT', 503, 'The request waited too long in the queue; try again shortly', ['job_id' => $job->id], 30),
            default => throw new ApiException($job->error_code ?? 'INFERENCE_FAILED', $job->error_transient ? 503 : 502,
                $job->error_message ?? 'The model could not answer this request', ['job_id' => $job->id], $job->error_transient ? 30 : null),
        };
    }

    /**
     * OpenAI content may be a string or a list of parts; only text parts are kept.
     *
     * @param  array<int, mixed>  $raw
     * @return list<array{role: string, content: string}>
     */
    private function messages(array $raw): array
    {
        $out = [];
        foreach ($raw as $i => $m) {
            $role = is_array($m) ? strtolower((string) ($m['role'] ?? '')) : '';
            if (! in_array($role, self::ROLES, true)) {
                throw ApiException::validation('Invalid request', ["messages.$i.role" => ['must be system, user or assistant']]);
            }
            $content = $m['content'] ?? null;
            if (is_array($content)) {
                $content = implode("\n", array_map(
                    fn ($p) => is_array($p) && ($p['type'] ?? null) === 'text' ? (string) ($p['text'] ?? '') : '',
                    $content,
                ));
            }
            if (! is_string($content) || ! mb_check_encoding($content, 'UTF-8')) {
                throw ApiException::validation('Invalid request', ["messages.$i.content" => ['must be text']]);
            }
            $out[] = ['role' => $role, 'content' => $content];
        }
        if (! in_array('user', array_column($out, 'role'), true)) {
            throw ApiException::validation('Invalid request', ['messages' => ['must contain a user message']]);
        }

        return $out;
    }

    private function idempotencyKey(Request $request): string
    {
        $header = (string) $request->header('Idempotency-Key', '');

        return preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $header) ? 'oa:'.$header : 'oa:'.Str::uuid7();
    }

    private function completion(Job $job, PayloadCipher $cipher): JsonResponse
    {
        $payload = $job->payload()->first();
        $content = $payload?->output_ct !== null ? $cipher->decrypt($job->id, 'output', $payload->output_ct, $payload->key_id) : '';
        $job->loadMissing('model');
        $in = $job->tokens_in;
        $out = $job->tokens_out;

        return new JsonResponse([
            'id' => 'chatcmpl-'.$job->id,
            'object' => 'chat.completion',
            'created' => ($job->finished_at ?? CarbonImmutable::now())->getTimestamp(),
            'model' => $job->model->tag,
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                // Ollama stops at num_predict = max_tokens; reaching it means the answer was cut.
                'finish_reason' => $out !== null && $out >= $job->max_tokens ? 'length' : 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => $in ?? 0,
                'completion_tokens' => $out ?? 0,
                'total_tokens' => ($in ?? 0) + ($out ?? 0),
            ],
        ]);
    }

    private function key(Request $request): ApiKey
    {
        return $request->attributes->get('api_key');
    }
}
