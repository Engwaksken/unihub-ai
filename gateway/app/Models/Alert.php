<?php

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $type
 * @property string $severity
 * @property string $status
 * @property string $message
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property string|null $acked_by
 * @property CarbonImmutable|null $acked_at
 * @property CarbonImmutable|null $resolved_at
 */
class Alert extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'acked_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'severity' => $this->severity,
            'status' => $this->status,
            'message' => $this->message,
            'first_seen_at' => self::iso($this->first_seen_at),
            'last_seen_at' => self::iso($this->last_seen_at),
            'acked_by' => $this->acked_by,
            'acked_at' => self::iso($this->acked_at),
            'resolved_at' => self::iso($this->resolved_at),
        ];
    }
}
