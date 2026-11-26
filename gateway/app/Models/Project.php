<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $slug
 * @property string $name
 * @property string|null $owner_ref
 * @property string|null $contact_email
 * @property string $environment
 * @property string $status
 * @property int $daily_limit
 * @property int $rpm_limit
 * @property int $queue_limit
 * @property CarbonImmutable|null $last_served_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 * @property-read Collection<int, Task> $tasks
 */
class Project extends BaseModel
{
    public const STATUSES = ['active', 'disabled', 'archived'];

    protected $fillable = [
        'organization_id', 'slug', 'name', 'owner_ref', 'contact_email', 'environment',
        'status', 'daily_limit', 'rpm_limit', 'queue_limit',
    ];

    protected function casts(): array
    {
        return [
            'daily_limit' => 'integer',
            'rpm_limit' => 'integer',
            'queue_limit' => 'integer',
            'last_served_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<Task, $this> */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'project_tasks');
    }

    /** @return HasMany<ApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
