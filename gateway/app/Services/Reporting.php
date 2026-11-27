<?php

namespace App\Services;

use App\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Usage aggregates from usage_events (completed + failed runs) and jobs (admissions). */
class Reporting
{
    public function __construct(private readonly Settings $settings) {}

    public const GROUPS = ['day', 'week', 'month', 'organization', 'project', 'key', 'model'];

    /**
     * @param  string[]|null  $projectIds  null = all projects (the actor's scope; filters only narrow it)
     * @param  array{organization_id?: string, api_key_id?: string, model?: string}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function usage(CarbonImmutable $from, CarbonImmutable $to, ?array $projectIds, string $groupBy, int $tzOffsetMinutes = 0, array $filters = []): array
    {
        $q = DB::table('usage_events as u')
            ->leftJoin('projects as p', 'p.id', '=', 'u.project_id')
            ->leftJoin('organizations as o', 'o.id', '=', 'p.organization_id')
            ->leftJoin('models as m', 'm.id', '=', 'u.model_id')
            ->leftJoin('jobs as j', 'j.id', '=', 'u.job_id')
            ->leftJoin('api_keys as k', 'k.id', '=', 'j.api_key_id')
            ->whereBetween('u.created_at', [BaseModel::dbTime($from), BaseModel::dbTime($to)]);
        if ($projectIds !== null) {
            $q->whereIn('u.project_id', $projectIds ?: ['__none__']);
        }
        if (isset($filters['organization_id'])) {
            $q->where('p.organization_id', $filters['organization_id']);
        }
        if (isset($filters['api_key_id'])) {
            $q->where('j.api_key_id', $filters['api_key_id']);
        }
        if (isset($filters['model'])) {
            $q->where('m.tag', $filters['model']);
        }
        $rows = $q->get(['u.created_at', 'u.project_id', 'p.slug', 'p.name', 'p.organization_id', 'o.name as org_name', 'm.tag',
            'j.api_key_id', 'k.label as key_label', 'k.prefix as key_prefix', 'u.status', 'u.tokens_in', 'u.tokens_out', 'u.queue_ms', 'u.inference_ms', 'u.total_ms']);

        $buckets = [];
        foreach ($rows as $r) {
            [$key, $label] = $this->bucket($r, $groupBy, $tzOffsetMinutes);
            $buckets[$key] ??= ['key' => $key, 'label' => $label, 'project_slug' => $r->slug, 'project_name' => $r->name, 'completed' => 0, 'failed' => 0,
                'tokens_in' => 0, 'tokens_out' => 0, 'tokens_known' => 0, 'queue' => [], 'inference' => [], 'total' => [], 'inference_ms_sum' => 0];
            $buckets[$key][$r->status === 'completed' ? 'completed' : 'failed']++;
            if ($r->tokens_in !== null || $r->tokens_out !== null) {
                $buckets[$key]['tokens_known']++;
                $buckets[$key]['tokens_in'] += (int) $r->tokens_in;
                $buckets[$key]['tokens_out'] += (int) $r->tokens_out;
            }
            $buckets[$key]['queue'][] = (int) $r->queue_ms;
            $buckets[$key]['inference'][] = (int) $r->inference_ms;
            $buckets[$key]['total'][] = (int) $r->total_ms;
            $buckets[$key]['inference_ms_sum'] += (int) $r->inference_ms;
        }

        $costPerMs = $this->costPerInferenceMs($from, $to);
        $out = [];
        foreach ($buckets as $b) {
            $runs = $b['completed'] + $b['failed'];
            $out[] = [
                'key' => $b['key'],
                'label' => $b['label'],
                'project_slug' => $groupBy === 'project' ? $b['project_slug'] : null,
                'project_name' => $groupBy === 'project' ? $b['project_name'] : null,
                'requests' => $runs,
                'completed' => $b['completed'],
                'failed' => $b['failed'],
                'failure_rate' => $runs ? round($b['failed'] / $runs, 4) : null,
                'tokens_in' => $b['tokens_known'] ? $b['tokens_in'] : null,
                'tokens_out' => $b['tokens_known'] ? $b['tokens_out'] : null,
                'p50_queue_ms' => self::percentile($b['queue'], 50),
                'p95_queue_ms' => self::percentile($b['queue'], 95),
                'p50_inference_ms' => self::percentile($b['inference'], 50),
                'p95_inference_ms' => self::percentile($b['inference'], 95),
                'p95_total_ms' => self::percentile($b['total'], 95),
                'inference_ms_sum' => $b['inference_ms_sum'],
                'est_cost_usd' => $costPerMs === null ? null : round($b['inference_ms_sum'] * $costPerMs, 4),
            ];
        }
        // Time buckets read chronologically; named groups alphabetically by what people see.
        $sortBy = in_array($groupBy, ['day', 'week', 'month'], true) ? 'key' : 'label';
        usort($out, fn ($a, $b) => strcmp((string) $a[$sortBy], (string) $b[$sortBy]));

        return $out;
    }

    /** @return array{0: string, 1: string} bucket key and display label */
    private function bucket(object $r, string $groupBy, int $tzOffsetMinutes): array
    {
        if (in_array($groupBy, ['day', 'week', 'month'], true)) {
            $key = CarbonImmutable::parse($r->created_at, 'UTC')->addMinutes($tzOffsetMinutes)
                ->format(match ($groupBy) {
                    'week' => 'o-\WW', 'month' => 'Y-m', default => 'Y-m-d'
                });

            return [$key, $key];
        }

        return match ($groupBy) {
            'project' => [(string) $r->project_id, (string) ($r->name ?? $r->project_id)],
            'organization' => [(string) ($r->organization_id ?? 'unknown'), (string) ($r->org_name ?? 'Unknown organization')],
            // Jobs already purged by retention have no key; they are grouped as unknown, not dropped.
            'key' => $r->api_key_id === null ? ['unknown', 'Unknown key']
                : [(string) $r->api_key_id, trim(($r->key_label ?? 'Key').' ('.($r->key_prefix ?? substr((string) $r->api_key_id, 0, 8)).')')],
            default => [(string) ($r->tag ?? 'unknown'), (string) ($r->tag ?? 'unknown')],
        };
    }

    /**
     * Hosting cost for the period divided by ALL inference time in the period (ADR D14).
     * Null when nothing ran (no meaningful allocation).
     */
    public function costPerInferenceMs(CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        $total = (int) DB::table('usage_events')->whereBetween('created_at', [BaseModel::dbTime($from), BaseModel::dbTime($to)])->sum('inference_ms');
        if ($total <= 0) {
            return null;
        }
        $periodCost = (float) $this->settings->get('monthly_hosting_cost_usd') * ($from->diffInSeconds($to) / (30.4375 * 86400));

        return $periodCost / $total;
    }

    public static function percentile(array $values, int $p): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $idx = (int) ceil($p / 100 * count($values)) - 1;

        return $values[max(0, min($idx, count($values) - 1))];
    }
}
