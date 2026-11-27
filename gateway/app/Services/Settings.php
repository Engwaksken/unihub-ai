<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Gateway policy settings: DB overrides on top of config('gateway.defaults').
 * Values are cached per instance; long-running workers call refresh() each loop.
 */
class Settings
{
    /** Validation rules for editable settings (management PATCH /settings). */
    public const RULES = [
        'global_queue_limit' => 'integer|min:0|max:100',
        'default_project_queue_limit' => 'integer|min:0|max:100',
        'default_rpm_limit' => 'integer|min:0|max:1000',
        'default_daily_limit' => 'integer|min:0|max:100000',
        'max_body_bytes' => 'integer|min:1024|max:65536',
        'max_output_tokens' => 'integer|min:1|max:1024',
        'queue_wait_max_seconds' => 'integer|min:10|max:3600',
        'inference_deadline_seconds' => 'integer|min:10|max:600',
        'result_ttl_hours' => 'integer|min:1|max:24',
        'metadata_retention_days' => 'integer|min:1|max:365',
        'key_rotation_overlap_days' => 'integer|min:0|max:30',
        'poll_rate_per_minute' => 'integer|min:1|max:600',
        'monthly_hosting_cost_usd' => 'numeric|min:0|max:100000',
        'alert_ram_pct' => 'integer|min:50|max:100',
        'alert_disk_pct' => 'integer|min:50|max:100',
        'alert_swap_pct' => 'integer|min:10|max:100',
        'alert_heartbeat_seconds' => 'integer|min:10|max:3600',
        'alert_tls_days' => 'integer|min:1|max:90',
        'telemetry_stale_seconds' => 'integer|min:30|max:3600',
        'approved_providers' => 'array|max:0', // external fallback stays disabled in MVP (PRD §08)
    ];

    private ?array $values = null;

    public function all(): array
    {
        if ($this->values === null) {
            $values = config('gateway.defaults');
            foreach (DB::table('settings')->get() as $row) {
                $values[$row->key] = json_decode($row->value, true);
            }
            $this->values = $values;
        }

        return $this->values;
    }

    public function get(string $key): mixed
    {
        $all = $this->all();
        if (! array_key_exists($key, $all)) {
            throw new InvalidArgumentException("Unknown setting {$key}");
        }

        return $all[$key];
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function set(string $key, mixed $value, string $actorRef): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_by' => $actorRef, 'updated_at' => now()],
        );
        $this->values = null;
    }

    public function refresh(): void
    {
        $this->values = null;
    }
}
