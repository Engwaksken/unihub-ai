<?php

namespace App\Services;

use App\Models\AiModel;
use App\Models\ApiKey;
use App\Models\BaseModel;
use App\Models\Job;
use App\Models\JobPayload;
use App\Models\Task;
use App\Support\ApiException;
use App\Support\RequestId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Atomic job admission (ADR D7, PRD §08). Runs in one IMMEDIATE transaction so
 * idempotency, queue capacity and quota counters cannot race.
 */
class AdmissionService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PayloadCipher $cipher,
        private readonly TokenEstimator $estimator,
    ) {}

    /**
     * Optional (OpenAI-compatible chat, phase C): input_kind ('text'|'chat'; chat input is
     * JSON {"messages": [...]}), model (an enabled model tag instead of the task version's),
     * estimate_text (the text to budget tokens for, when input is not plain text).
     *
     * @param  array{task: string, input: string, max_tokens: int, idempotency_key: string, input_kind?: string, model?: ?string, estimate_text?: string}  $req
     * @return array{0: Job, 1: bool} [job, replayed]
     */
    public function submit(ApiKey $key, array $req): array
    {
        $kind = $req['input_kind'] ?? Job::INPUT_TEXT;
        $hashed = [$req['task'], $req['input'], $req['max_tokens']];
        if ($kind !== Job::INPUT_TEXT || ! empty($req['model'])) {
            $hashed[] = $kind;
            $hashed[] = $req['model'] ?? null;
        }
        $inputHash = hash('sha256', json_encode($hashed, JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($key, $req, $inputHash, $kind) {
            $project = $key->project()->first();
            $now = CarbonImmutable::now('UTC');

            if (! $project->isActive()) {
                throw new ApiException('PROJECT_DISABLED', 403, 'Project is not active');
            }

            // 1. Idempotency: identical body returns original job and consumes no quota.
            $existing = Job::where('project_id', $project->id)->where('idem_key', $req['idempotency_key'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->input_hash, $inputHash)) {
                    throw new ApiException('IDEMPOTENCY_CONFLICT', 409, 'idempotency_key reused with a different body');
                }

                return [$existing, true];
            }

            $this->settings->refresh();
            if ($this->settings->get('admission') !== 'open') {
                throw new ApiException('ADMISSION_PAUSED', 503, 'Service temporarily not accepting jobs', [], 60);
            }

            // 2. Task allowlist + published version + enabled model.
            $task = Task::where('key', $req['task'])->first();
            $allowed = $task !== null && DB::table('project_tasks')->where('project_id', $project->id)->where('task_id', $task->id)->exists();
            if (! $allowed) {
                throw new ApiException('TASK_NOT_ALLOWED', 403, 'Task not allowed for this project');
            }
            $version = $task->currentVersion();
            $model = $version?->model;
            if ($version !== null && ! empty($req['model']) && $req['model'] !== $model?->tag) {
                $model = AiModel::where('tag', $req['model'])->first();
                if ($model === null || ! $model->enabled || ! $model->installed) {
                    throw new ApiException('MODEL_NOT_FOUND', 404, "Model '{$req['model']}' is not available on this gateway", [
                        'available' => AiModel::where('enabled', true)->where('installed', true)->orderBy('tag')->pluck('tag')->all(),
                    ]);
                }
            }
            if ($version === null || $model === null || ! $model->enabled || ! $model->installed) {
                throw new ApiException('SERVICE_UNAVAILABLE', 503, 'Task is not currently available', [], 300);
            }

            // 3. Token budget — reject, never truncate.
            $maxTokens = min($req['max_tokens'], $version->output_cap, $this->settings->int('max_output_tokens'));
            $estInput = $this->estimator->estimate($version->system_template, $req['estimate_text'] ?? $req['input']);
            $numCtx = (int) config('gateway.ollama.num_ctx');
            if ($estInput + $maxTokens > $numCtx) {
                throw new ApiException('TOKEN_BUDGET', 422, 'Input too long for the context budget', [
                    'estimated_input_tokens' => $estInput, 'max_tokens' => $maxTokens, 'context_tokens' => $numCtx,
                ]);
            }

            // 4. Bounded queue (global + per project).
            $globalQueued = Job::where('state', Job::QUEUED)->count();
            if ($globalQueued >= $this->settings->int('global_queue_limit')) {
                throw new ApiException('QUEUE_FULL', 429, 'Try later', [], 30);
            }
            $projectQueued = Job::where('state', Job::QUEUED)->where('project_id', $project->id)->count();
            if ($projectQueued >= $project->queue_limit) {
                throw new ApiException('QUEUE_FULL', 429, 'Try later', [], 30);
            }

            // 5. Quotas (admitted requests; idempotent repeats never reach here).
            $minuteStart = $now->format('Y-m-d H:i:00');
            $dayStart = $now->format('Y-m-d');
            $minuteCount = $this->counter($project->id, 'minute', $minuteStart);
            if ($minuteCount >= $project->rpm_limit) {
                throw new ApiException('QUOTA_EXCEEDED', 429, 'Per-minute quota exceeded', [], 60 - (int) $now->format('s'));
            }
            $dayCount = $this->counter($project->id, 'day', $dayStart);
            if ($dayCount >= $project->daily_limit) {
                throw new ApiException('QUOTA_EXCEEDED', 429, 'Daily quota exceeded', [], (int) $now->diffInSeconds($now->addDay()->startOfDay()));
            }

            // 6. Admit.
            $jobId = (string) Str::uuid7();
            $job = new Job;
            $job->forceFill([
                'id' => $jobId,
                'project_id' => $project->id,
                'api_key_id' => $key->id,
                'task_version_id' => $version->id,
                'model_id' => $model->id,
                'state' => Job::QUEUED,
                'input_kind' => $kind,
                'idem_key' => $req['idempotency_key'],
                'input_hash' => $inputHash,
                'max_tokens' => $maxTokens,
                'est_input_tokens' => $estInput,
                'attempts' => 0,
                'available_at' => $now,
                'queued_at' => $now,
                'queue_deadline_at' => $now->addSeconds($this->settings->int('queue_wait_max_seconds')),
                'request_id' => RequestId::current(),
            ])->save();

            [$ct, $keyId] = $this->cipher->encrypt($jobId, 'input', $req['input']);
            JobPayload::create(['job_id' => $jobId, 'key_id' => $keyId, 'input_ct' => $ct, 'created_at' => BaseModel::dbTime($now)]);

            $this->increment($project->id, 'minute', $minuteStart);
            $this->increment($project->id, 'day', $dayStart);

            return [$job, false];
        });
    }

    private function counter(string $projectId, string $type, string $start): int
    {
        return (int) DB::table('quota_counters')
            ->where(['project_id' => $projectId, 'window_type' => $type, 'window_start' => $start])
            ->value('count');
    }

    private function increment(string $projectId, string $type, string $start): void
    {
        DB::table('quota_counters')->insertOrIgnore(['project_id' => $projectId, 'window_type' => $type, 'window_start' => $start, 'count' => 0]);
        DB::table('quota_counters')
            ->where(['project_id' => $projectId, 'window_type' => $type, 'window_start' => $start])
            ->increment('count');
    }
}
