<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $project_id
 * @property string|null $label
 * @property string $environment
 * @property string $prefix
 * @property string $digest
 * @property string|null $rotated_from_id
 * @property string $created_by
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 * @property-read Project $project
 */
class ApiKey extends BaseModel
{
    protected $fillable = ['project_id', 'label', 'environment', 'prefix', 'digest', 'rotated_from_id', 'created_by', 'expires_at'];

    protected $hidden = ['digest'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isUsable(?DateTimeInterface $now = null): bool
    {
        $now ??= now();

        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at > $now);
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at !== null && $this->expires_at <= now() => 'expired',
            default => 'active',
        };
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'label' => $this->label,
            'environment' => $this->environment,
            'prefix' => $this->prefix,
            'status' => $this->status(),
            'rotated_from_id' => $this->rotated_from_id,
            'created_by' => $this->created_by,
            'created_at' => self::iso($this->created_at),
            'expires_at' => self::iso($this->expires_at),
            'revoked_at' => self::iso($this->revoked_at),
            'last_used_at' => self::iso($this->last_used_at),
        ];
    }
}
