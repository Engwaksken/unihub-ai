<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property Carbon $occurred_at
 * @property string|null $request_id
 * @property string|null $actor_user_id
 * @property string|null $actor_label
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $gateway_project_uuid
 * @property string|null $ip_address
 * @property array<mixed>|null $metadata
 *
 * Append-only CMS audit record. Rows can be inserted but never changed or
 * deleted through the application.
 */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Audit events are append-only.');
        });
        static::deleting(function () {
            throw new LogicException('Audit events are append-only.');
        });
    }

    /**
     * Limit a query to what the given project scope may see. Null = all.
     *
     * @param  list<string>|null  $projectUuids
     */
    public function scopeVisibleTo(Builder $query, ?array $projectUuids): Builder
    {
        if ($projectUuids === null) {
            return $query;
        }

        return $query->whereIn('gateway_project_uuid', $projectUuids === [] ? ['-'] : $projectUuids);
    }
}
