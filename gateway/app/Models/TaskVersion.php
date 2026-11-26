<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $task_id
 * @property int $version
 * @property string $system_template
 * @property int $output_cap
 * @property string $output_format
 * @property string $model_id
 * @property string $created_by
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $created_at
 * @property-read Task $task
 * @property-read AiModel|null $model
 */
class TaskVersion extends BaseModel
{
    protected $fillable = ['task_id', 'version', 'system_template', 'output_cap', 'output_format', 'model_id', 'created_by'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'output_cap' => 'integer', 'published_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        // Published versions are immutable (PRD §07).
        static::updating(function (TaskVersion $v) {
            if ($v->getOriginal('published_at') !== null) {
                throw new LogicException('Published task versions are immutable.');
            }
        });
        static::deleting(function (TaskVersion $v) {
            if ($v->published_at !== null) {
                throw new LogicException('Published task versions are immutable.');
            }
        });
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<AiModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'system_template' => $this->system_template,
            'output_cap' => $this->output_cap,
            'output_format' => $this->output_format,
            'model_id' => $this->model_id,
            'published_at' => self::iso($this->published_at),
            'created_by' => $this->created_by,
            'created_at' => self::iso($this->created_at),
        ];
    }
}
