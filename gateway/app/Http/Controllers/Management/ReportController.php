<?php

namespace App\Http\Controllers\Management;

use App\Models\BaseModel;
use App\Models\Job;
use App\Models\Project;
use App\Services\Reporting;
use App\Services\Settings;
use App\Services\Telemetry;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Overview summary and usage reports (scoped by actor). */
class ReportController extends ManagementController
{
    public function summary(Request $request, Reporting $reporting, Telemetry $telemetry, Settings $settings): JsonResponse
    {
        $actor = $this->actor($request);
        $actor->authorize('summary.view');
        [$from, $to] = $this->range($request);
        $scope = $this->projectScope($request, 'summary.view');
        $now = CarbonImmutable::now('UTC');
        $dayStart = $now->startOfDay();

        $jobs = function () use ($scope) {
            $q = Job::query();
            if ($scope !== null) {
                $q->whereIn('project_id', $scope ?: ['__none__']);
            }

            return $q;
        };
        $todayStates = $jobs()->where('created_at', '>=', BaseModel::dbTime($dayStart))
            ->select('state', DB::raw('count(*) as n'))->groupBy('state')->pluck('n', 'state');
        $finished = (int) ($todayStates['completed'] ?? 0) + (int) ($todayStates['failed'] ?? 0) + (int) ($todayStates['expired'] ?? 0);

        $projects = Project::where('status', 'active');
        if ($scope !== null) {
            $projects->whereIn('id', $scope ?: ['__none__']);
        }

        $rows = $reporting->usage($from, $to, $scope, 'day');
        $latest = $telemetry->latest();
        $stale = $telemetry->isStale($latest);
        $totalMs = DB::table('usage_events')->whereBetween('created_at', [BaseModel::dbTime($from), BaseModel::dbTime($to)]);
        if ($scope !== null) {
            $totalMs->whereIn('project_id', $scope ?: ['__none__']);
        }
        $totalList = $totalMs->pluck('total_ms')->map(fn ($v) => (int) $v)->all();

        return $this->data([
            'generated_at' => BaseModel::iso($now),
            'range' => ['from' => BaseModel::iso($from), 'to' => BaseModel::iso($to)],
            'active_projects' => $projects->count(),
            'requests_today' => (int) array_sum($todayStates->all()),
            'completion_rate' => $finished > 0 ? round(((int) ($todayStates['completed'] ?? 0)) / $finished, 4) : null,
            'queue_depth' => $jobs()->where('state', Job::QUEUED)->count(),
            'running' => $jobs()->where('state', Job::RUNNING)->count(),
            'p95_total_ms' => Reporting::percentile($totalList, 95),
            'ram_used_pct' => $stale ? null : $latest?->ram_used_pct,
            'telemetry_sampled_at' => BaseModel::iso($latest?->sampled_at),
            'telemetry_stale' => $stale,
            'model_status' => $stale ? 'unknown' : ($latest->ollama_status ?? 'unknown'),
            'loaded_model' => $stale ? null : $latest?->loaded_model,
            'admission' => $settings->get('admission'),
            'trend' => array_map(fn ($r) => [
                'day' => $r['key'], 'requests' => $r['requests'], 'failed' => $r['failed'], 'p95_total_ms' => $r['p95_total_ms'],
            ], $rows),
        ]);
    }

    public function usage(Request $request, Reporting $reporting): JsonResponse|Response
    {
        $this->actor($request)->authorize('usage.view');
        [$from, $to] = $this->range($request);
        $q = $this->validate($request->query(), [
            'group_by' => 'nullable|in:day,project,model',
            'format' => 'nullable|in:json,csv',
            'tz' => 'nullable|timezone:all',
        ]);
        $groupBy = $q['group_by'] ?? 'day';
        $offset = isset($q['tz']) ? intdiv((new DateTimeZone($q['tz']))->getOffset(new \DateTime('now', new DateTimeZone('UTC'))), 60) : 0;
        $rows = $reporting->usage($from, $to, $this->projectScope($request, 'usage.view'), $groupBy, $offset);

        if (($q['format'] ?? 'json') === 'csv') {
            $this->audit($request, 'usage.exported', 'usage', null, $request->query('project_id'), ['group_by' => $groupBy, 'from' => BaseModel::iso($from), 'to' => BaseModel::iso($to)]);

            return Csv::download("usage-{$groupBy}-{$from->format('Ymd')}-{$to->format('Ymd')}.csv",
                ['key', 'project_slug', 'project_name', 'requests', 'completed', 'failed', 'failure_rate', 'tokens_in', 'tokens_out',
                    'p50_queue_ms', 'p95_queue_ms', 'p50_inference_ms', 'p95_inference_ms', 'p95_total_ms', 'inference_ms_sum', 'est_cost_usd'], $rows);
        }

        return new JsonResponse(['data' => $rows, 'meta' => [
            'from' => BaseModel::iso($from), 'to' => BaseModel::iso($to), 'group_by' => $groupBy,
            'cost_note' => 'Estimate: hosting cost for the period allocated by share of inference time.',
        ]]);
    }

    /** @return string[]|null null = unrestricted */
    private function projectScope(Request $request, string $capability): ?array
    {
        $actor = $this->actor($request);
        $requested = $request->query('project_id');
        if ($requested !== null) {
            $requested = $this->uuid((string) $requested);
            $actor->authorize($capability, $requested);

            return [$requested];
        }

        return $actor->isGlobal($capability) ? null : $actor->projectsWith($capability);
    }
}
