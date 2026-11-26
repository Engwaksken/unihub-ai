<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $key
 * @property string|null $description
 * @property CarbonImmutable|null $created_at
 * @property-read Collection<int, TaskVersion> $versions
 */
class Task extends BaseModel
{
    protected $fillable = ['key', 'description'];

    /** @return HasMany<TaskVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TaskVersion::class)->orderBy('version');
    }

    /** Latest published version: the one new jobs use. */
    public function currentVersion(): ?TaskVersion
    {
        return TaskVersion::query()
            ->where('task_id', $this->id)
            ->whereNotNull('published_at')
            ->orderByDesc('version')
            ->first();
    }
}
