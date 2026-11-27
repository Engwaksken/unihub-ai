<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\BaseModel;
use App\Models\HealthSample;
use App\Models\Job;
use App\Services\Ollama\OllamaClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Host/worker/Ollama/TLS sampling and alert rules (ADR D13, PRD §08).
 * Values that cannot be measured are stored as NULL ("unknown"), never 0.
 */
class Telemetry
{
    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly Settings $settings,
        private readonly ServiceProbe $services,
    ) {}

    public function sample(): HealthSample
    {
        $mem = $this->memory();
        $heartbeat = DB::table('worker_heartbeats')->max('heartbeat_at');
        $ps = $this->ollama->ps();
        $loaded = $ps[0]['name'] ?? $ps[0]['model'] ?? null;

        $sample = HealthSample::create([
            'host_id' => config('gateway.host_id'),
            'sampled_at' => CarbonImmutable::now('UTC'),
            'cpu_pct' => $this->cpu(),
            'ram_used_pct' => $mem ? round($mem['used'] / $mem['total'] * 100, 1) : null,
            'ram_total_mb' => $mem['total'] ?? null,
            'ram_used_mb' => $mem['used'] ?? null,
            'swap_total_mb' => $mem['swap_total'] ?? null,
            'swap_used_mb' => $mem['swap_used'] ?? null,
            'disk_used_pct' => $this->disk(),
            'queue_depth' => Job::where('state', Job::QUEUED)->count(),
            'running' => Job::where('state', Job::RUNNING)->count(),
            'worker_heartbeat_at' => $heartbeat,
            'ollama_status' => $ps === null ? 'unreachable' : ($loaded ? 'loaded' : 'idle'),
            'loaded_model' => $loaded,
            'tls_expires_at' => $this->tlsExpiry(),
            'services' => $this->services->statuses(),
        ]);
        $this->evaluate($sample->fresh());

        return $sample;
    }

    /** Opens, refreshes or resolves alerts from a sample. One open alert per type. */
    public function evaluate(HealthSample $s): void
    {
        $now = CarbonImmutable::now('UTC');
        $hbAge = $s->worker_heartbeat_at ? $s->worker_heartbeat_at->diffInSeconds($now) : null;
        $tlsDays = $s->tls_expires_at ? $now->diffInDays($s->tls_expires_at, false) : null;
        $swapPct = self::swapPct($s);
        // Only units systemd reports as stopped count; "unknown" is not evidence of an outage.
        $down = array_column(array_filter($s->services ?? [], fn ($u) => in_array($u['status'], ['inactive', 'failed'], true)), 'name');

        $rules = [
            'ram_high' => [$s->ram_used_pct !== null && $s->ram_used_pct > $this->settings->int('alert_ram_pct'), 'warning', "RAM usage {$s->ram_used_pct}%"],
            'disk_high' => [$s->disk_used_pct !== null && $s->disk_used_pct > $this->settings->int('alert_disk_pct'), 'warning', "Disk usage {$s->disk_used_pct}%"],
            'queue_saturated' => [$s->queue_depth >= $this->settings->int('global_queue_limit'), 'warning', "Queue full ({$s->queue_depth} queued)"],
            'worker_heartbeat_missing' => [$hbAge === null || $hbAge > $this->settings->int('alert_heartbeat_seconds'), 'critical',
                $hbAge === null ? 'No worker heartbeat recorded' : 'Worker heartbeat missing for '.(int) $hbAge.'s'],
            'ollama_unreachable' => [$s->ollama_status === 'unreachable', 'critical', 'Ollama is not responding on loopback'],
            'swap_high' => [$swapPct !== null && $swapPct > $this->settings->int('alert_swap_pct'), 'warning', "Swap usage {$swapPct}%"],
            'service_down' => [$down !== [], 'critical', 'Service not running: '.implode(', ', $down)],
            'tls_expiring' => [$tlsDays !== null && $tlsDays < $this->settings->int('alert_tls_days'), 'warning', 'TLS certificate expires in '.(int) $tlsDays.' days'],
        ];

        foreach ($rules as $type => [$firing, $severity, $message]) {
            $open = Alert::where('type', $type)->whereIn('status', ['open', 'acknowledged'])->first();
            if ($firing) {
                if ($open) {
                    $open->update(['last_seen_at' => $now, 'message' => $message]);
                } else {
                    Alert::create(['id' => (string) Str::uuid7(), 'type' => $type, 'severity' => $severity, 'status' => 'open',
                        'message' => $message, 'first_seen_at' => $now, 'last_seen_at' => $now]);
                }
            } elseif ($open) {
                $open->update(['status' => 'resolved', 'resolved_at' => $now]);
            }
        }
    }

    public function latest(): ?HealthSample
    {
        return HealthSample::orderByDesc('sampled_at')->first();
    }

    public function isStale(?HealthSample $s): bool
    {
        return $s === null || $s->sampled_at->diffInSeconds(now()) > $this->settings->int('telemetry_stale_seconds');
    }

    private function cpu(): ?float
    {
        $read = function (): ?array {
            $lines = @file('/proc/stat');
            $line = is_array($lines) ? ($lines[0] ?? null) : null;
            if (! $line || ! str_starts_with($line, 'cpu ')) {
                return null;
            }
            $v = array_map('intval', preg_split('/\s+/', trim(substr($line, 4))));

            return [array_sum($v), $v[3] + ($v[4] ?? 0)];
        };
        $a = $read();
        if ($a === null) {
            return null;
        }
        usleep(250_000);
        $b = $read();
        $total = $b[0] - $a[0];

        return $total > 0 ? round((1 - ($b[1] - $a[1]) / $total) * 100, 1) : null;
    }

    /** @return array{total: int, used: int, swap_total: int|null, swap_used: int|null}|null in MB */
    private function memory(): ?array
    {
        $info = @file_get_contents('/proc/meminfo');

        return $info ? self::parseMeminfo($info) : null;
    }

    /** @return array{total: int, used: int, swap_total: int|null, swap_used: int|null}|null in MB */
    public static function parseMeminfo(string $info): ?array
    {
        if (! preg_match('/^MemTotal:\s+(\d+)/m', $info, $t) || ! preg_match('/^MemAvailable:\s+(\d+)/m', $info, $a)) {
            return null;
        }
        $total = intdiv((int) $t[1], 1024);
        $swap = preg_match('/^SwapTotal:\s+(\d+)/m', $info, $st) && preg_match('/^SwapFree:\s+(\d+)/m', $info, $sf);

        return [
            'total' => $total,
            'used' => $total - intdiv((int) $a[1], 1024),
            'swap_total' => $swap ? intdiv((int) $st[1], 1024) : null,
            'swap_used' => $swap ? intdiv((int) $st[1] - (int) $sf[1], 1024) : null,
        ];
    }

    /** Null when unmeasured or when the host has no swap (0 MB is not "0 % used"). */
    public static function swapPct(?HealthSample $s): ?float
    {
        return $s && $s->swap_total_mb && $s->swap_used_mb !== null ? round($s->swap_used_mb / $s->swap_total_mb * 100, 1) : null;
    }

    private function disk(): ?float
    {
        $total = @disk_total_space(base_path());
        $free = @disk_free_space(base_path());

        return ($total && $free !== false) ? round((1 - $free / $total) * 100, 1) : null;
    }

    private function tlsExpiry(): ?CarbonImmutable
    {
        $path = config('gateway.tls_cert_path');
        if (! $path || ! is_readable($path)) {
            return null;
        }
        $cert = @openssl_x509_parse((string) file_get_contents($path));

        return isset($cert['validTo_time_t']) ? CarbonImmutable::createFromTimestampUTC($cert['validTo_time_t']) : null;
    }

    public static function present(?HealthSample $s, bool $stale): array
    {
        return [
            'sampled_at' => BaseModel::iso($s?->sampled_at),
            'stale' => $stale,
            'host_id' => $s?->host_id,
            'cpu_pct' => $s?->cpu_pct,
            'ram_used_pct' => $s?->ram_used_pct,
            'ram_total_mb' => $s?->ram_total_mb,
            'ram_used_mb' => $s?->ram_used_mb,
            'swap_total_mb' => $s?->swap_total_mb,
            'swap_used_mb' => $s?->swap_used_mb,
            'swap_used_pct' => self::swapPct($s),
            'disk_used_pct' => $s?->disk_used_pct,
            'queue_depth' => $s?->queue_depth,
            'running' => $s?->running,
            'worker_heartbeat_at' => BaseModel::iso($s?->worker_heartbeat_at),
            'ollama_status' => $s->ollama_status ?? 'unknown',
            'loaded_model' => $s?->loaded_model,
            'tls_expires_at' => BaseModel::iso($s?->tls_expires_at),
            'services' => $s->services ?? [],
        ];
    }
}
