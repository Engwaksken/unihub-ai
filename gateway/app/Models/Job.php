<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $project_id
 * @property string|null $api_key_id
 * @property string $task_version_id
 * @property string $model_id
 * @property string $state
 * @property string $idem_key
 * @property string $input_hash
 * @property int $max_tokens
 * @property int $est_input_tokens
 * @property int $attempts
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable $queued_at
 * @property CarbonImmutable $queue_deadline_at
 * @property CarbonImmutable|null $lease_until
 * @property string|null $worker_id
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property bool $error_transient
 * @property int|null $tokens_in
 * @property int|null $tokens_out
 * @property string|null $retry_of
 * @property string $request_id
 * @property CarbonImmutable|null $created_at
 * @property-read Project $project
 * @property-read TaskVersion $taskVersion
 * @property-read AiModel $model
 * @property-read JobPayload|null $payload
 */
class Job extends BaseModel
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const STATES = [self::QUEUED, self::RUNNING, self::COMPLETED, self::FAILED, self::CANCELLED, self::EXPIRED];

    public const TERMINAL = [self::COMPLETED, self::FAILED, self::CANCELLED, self::EXPIRED];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_tokens' => 'integer',
            'est_input_tokens' => 'integer',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'error_transient' => 'boolean',
            'available_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'queue_deadline_at' => 'immutable_datetime',
            'lease_until' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<TaskVersion, $this> */
    public function taskVersion(): BelongsTo
    {
        return $this->belongsTo(TaskVersion::class);
    }

    /** @return BelongsTo<AiModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    /** @return HasOne<JobPayload, $this> */
    public function payload(): HasOne
    {
        return $this->hasOne(JobPayload::class);
    }

    /** Metadata only — never payloads (PRD §03 "no prompt display by default"). */
    public function toMetadata(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'task' => $this->taskVersion->task->key,
            'task_version' => $this->taskVersion->version,
            'model' => $this->model->tag,
            'state' => $this->state,
            'attempts' => $this->attempts,
            'max_tokens' => $this->max_tokens,
            'tokens_in' => $this->tokens_in,
            'tokens_out' => $this->tokens_out,
            'error_code' => $this->error_code,
            'retry_of' => $this->retry_of,
            'request_id' => $this->request_id,
            'queued_at' => self::iso($this->queued_at),
            'started_at' => self::iso($this->started_at),
            'finished_at' => self::iso($this->finished_at),
            'queue_ms' => $this->started_at ? (int) $this->queued_at->diffInMilliseconds($this->started_at) : null,
            'inference_ms' => $this->started_at && $this->finished_at ? (int) $this->started_at->diffInMilliseconds($this->finished_at) : null,
            'expires_at' => self::iso($this->expires_at),
            'created_at' => self::iso($this->created_at),
        ];
    }
}
