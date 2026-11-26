<?php

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $actor_ref
 * @property string|null $actor_name
 * @property string $action
 * @property string $target_type
 * @property string|null $target_id
 * @property string|null $project_id
 * @property array<string, mixed>|null $metadata
 * @property string|null $request_id
 * @property CarbonImmutable|null $created_at
 */
class AuditEvent extends BaseModel
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'source' => 'gateway',
            'actor_ref' => $this->actor_ref,
            'actor_name' => $this->actor_name,
            'action' => $this->action,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'project_id' => $this->project_id,
            'metadata' => $this->metadata,
            'request_id' => $this->request_id,
            'created_at' => self::iso($this->created_at),
        ];
    }
}
