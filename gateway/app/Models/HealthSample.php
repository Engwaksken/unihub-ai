<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $host_id
 * @property CarbonImmutable $sampled_at
 * @property float|null $cpu_pct
 * @property float|null $ram_used_pct
 * @property int|null $ram_total_mb
 * @property int|null $ram_used_mb
 * @property float|null $disk_used_pct
 * @property int $queue_depth
 * @property int $running
 * @property CarbonImmutable|null $worker_heartbeat_at
 * @property string $ollama_status
 * @property string|null $loaded_model
 * @property CarbonImmutable|null $tls_expires_at
 */
class HealthSample extends Model
{
    public $timestamps = false;

    protected $dateFormat = BaseModel::DATE_FORMAT;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'immutable_datetime',
            'worker_heartbeat_at' => 'immutable_datetime',
            'tls_expires_at' => 'immutable_datetime',
        ];
    }
}
