<?php

namespace App\Services;

use App\Models\Job;
use App\Services\Ollama\InferenceException;
use App\Services\Ollama\OllamaClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Executes one claimed job end-to-end. Never logs prompts or outputs. */
class InferenceRunner
{
    public function __construct(
        private readonly JobQueue $queue,
        private readonly OllamaClient $ollama,
        private readonly PayloadCipher $cipher,
        private readonly Settings $settings,
    ) {}

    /** @return string completed|requeued|failed|lost */
    public function run(Job $job, string $workerId): string
    {
        $version = $job->taskVersion;
        $payload = $job->payload;

        try {
            if ($payload === null) {
                throw new InferenceException('INFERENCE_FAILED', 'Job input no longer available', false);
            }
            $input = $this->cipher->decrypt($job->id, 'input', $payload->input_ct, $payload->key_id);

            if ($job->input_kind === Job::INPUT_CHAT) {
                // {"messages": [...], "temperature": ?float}: validated at admission.
                $chat = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
                $result = $this->ollama->chatMessages(
                    $job->model->tag,
                    $version->system_template,
                    $chat['messages'],
                    $job->max_tokens,
                    'text',
                    $this->settings->int('inference_deadline_seconds'),
                    isset($chat['temperature']) ? (float) $chat['temperature'] : null,
                );
                unset($chat);
            } else {
                $result = $this->ollama->chat(
                    $job->model->tag,
                    $version->system_template,
                    $input,
                    $job->max_tokens,
                    $version->output_format,
                    $this->settings->int('inference_deadline_seconds'),
                );
            }
            unset($input);

            if ($version->output_format === 'json' && $job->input_kind !== Job::INPUT_CHAT) {
                json_decode($result['content']);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new InferenceException('OUTPUT_INVALID', 'Model output was not valid JSON', false);
                }
            }

            $ct = $this->cipher->encryptWithKey($job->id, 'output', $result['content'], $payload->key_id);
            $ok = $this->queue->complete($job, $workerId, $ct, $result['tokens_in'], $result['tokens_out']);

            return $ok ? 'completed' : 'lost';
        } catch (InferenceException $e) {
            Log::warning('job inference failed', ['job_id' => $job->id, 'code' => $e->errorCode, 'transient' => $e->transient]);

            return $this->queue->fail($job, $workerId, $e->errorCode, $e->getMessage(), $e->transient);
        } catch (Throwable $e) {
            Log::error('job processing error', ['job_id' => $job->id, 'exception' => $e::class]);

            return $this->queue->fail($job, $workerId, 'INFERENCE_FAILED', 'Internal processing error', false);
        }
    }
}
