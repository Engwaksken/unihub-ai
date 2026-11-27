<?php

namespace App\Http\Controllers\Management;

use App\Models\Alert;
use App\Models\AuditEvent;
use App\Models\BaseModel;
use App\Models\HealthSample;
use App\Services\AuditLogger;
use App\Services\Settings;
use App\Services\Telemetry;
use App\Support\ApiException;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Health, alerts, admission control, settings and audit. */
class OpsController extends ManagementController
{
    public function health(Request $request, Telemetry $telemetry): JsonResponse
    {
        $this->actor($request)->authorize('health.view');
        $hours = max(1, min(168, (int) $request->query('hours', 6)));
        $latest = $telemetry->latest();
        $history = HealthSample::where('sampled_at', '>=', BaseModel::dbTime(now()->subHours($hours)))
            ->orderBy('sampled_at')->get()
            ->map(fn (HealthSample $s) => [
                'sampled_at' => BaseModel::iso($s->sampled_at), 'cpu_pct' => $s->cpu_pct, 'ram_used_pct' => $s->ram_used_pct,
                'disk_used_pct' => $s->disk_used_pct, 'swap_used_pct' => Telemetry::swapPct($s), 'queue_depth' => $s->queue_depth,
            ])->all();
        $workers = DB::table('worker_heartbeats')->orderByDesc('heartbeat_at')->get()->map(fn ($w) => [
            'worker_id' => $w->worker_id, 'pid' => $w->pid, 'busy' => $w->current_job_id !== null,
            'started_at' => BaseModel::iso(new \DateTimeImmutable($w->started_at, new \DateTimeZone('UTC'))),
            'heartbeat_at' => BaseModel::iso(new \DateTimeImmutable($w->heartbeat_at, new \DateTimeZone('UTC'))),
        ])->all();

        return $this->data(['current' => Telemetry::present($latest, $telemetry->isStale($latest)), 'workers' => $workers, 'history' => $history]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $this->actor($request)->authorize('health.view');
        $q = Alert::query();
        if ($status = $request->query('status')) {
            $q->whereIn('status', explode(',', $status));
        }

        return $this->page($request, $q, fn (Alert $a) => $a->toApi());
    }

    public function ackAlert(Request $request, string $id): JsonResponse
    {
        $this->actor($request)->authorize('alert.ack');
        $alert = Alert::find($this->uuid($id)) ?? throw ApiException::notFound();
        if ($alert->status === 'open') {
            $alert->update(['status' => 'acknowledged', 'acked_by' => $this->actor($request)->ref(), 'acked_at' => now()]);
            $this->audit($request, 'alert.acknowledged', 'alert', $alert->id, null, ['type' => $alert->type]);
        } elseif ($alert->status === 'resolved') {
            throw ApiException::invalidState('Alert already resolved');
        }

        return $this->data($alert->fresh()->toApi());
    }

    public function admission(Request $request, Settings $settings): JsonResponse
    {
        $this->actor($request)->authorize('admission.manage');
        $data = $this->validate($request->json()->all(), ['state' => 'required|in:open,paused', 'reason' => 'required|string|min:3|max:200']);
        $before = $settings->get('admission');
        $settings->set('admission', $data['state'], $this->actor($request)->ref());
        if ($before !== $data['state']) {
            $this->audit($request, 'admission.'.($data['state'] === 'paused' ? 'paused' : 'resumed'), 'gateway', null, null, ['reason' => $data['reason']]);
        }

        return $this->data(['admission' => $data['state']]);
    }

    public function settings(Request $request, Settings $settings): JsonResponse
    {
        $this->actor($request)->authorize('settings.view');

        return $this->data($settings->all());
    }

    public function updateSettings(Request $request, Settings $settings): JsonResponse
    {
        $this->actor($request)->authorize('settings.policy');
        $input = $request->json()->all();
        $unknown = array_diff(array_keys($input), array_keys(Settings::RULES));
        if ($unknown !== [] || $input === []) {
            throw ApiException::validation('Unknown or read-only settings', array_fill_keys(array_values($unknown) ?: ['body'], ['not editable']));
        }
        $data = $this->validate($input, array_map(fn ($r) => 'required|'.$r, array_intersect_key(Settings::RULES, $input)));
        if (isset($data['default_project_queue_limit']) && $data['default_project_queue_limit'] > ($data['global_queue_limit'] ?? $settings->int('global_queue_limit'))) {
            throw ApiException::validation('Invalid settings', ['default_project_queue_limit' => ['must not exceed global_queue_limit']]);
        }
        $before = $settings->all();
        foreach ($data as $k => $v) {
            $settings->set($k, $v, $this->actor($request)->ref());
        }
        $this->audit($request, 'settings.updated', 'settings', null, null, AuditLogger::diff($before, $settings->all(), array_keys($data)));

        return $this->data($settings->all());
    }

    public function auditLog(Request $request): JsonResponse|Response
    {
        $actor = $this->actor($request);
        $actor->authorize('audit.view');
        $q = AuditEvent::query();
        if (! $actor->isGlobal('audit.view')) {
            // Project-scoped roles see only events about their projects.
            $q->whereIn('project_id', $actor->projectsWith('audit.view') ?: ['__none__']);
        }
        if ($p = $request->query('project_id')) {
            $q->where('project_id', $p);
        }
        foreach (['action' => 'action', 'actor' => 'actor_ref', 'target_type' => 'target_type'] as $param => $col) {
            if ($v = $request->query($param)) {
                $q->where($col, $v);
            }
        }
        if ($request->query('from') || $request->query('to')) {
            [$from, $to] = $this->range($request);
            $q->whereBetween('created_at', [BaseModel::dbTime($from), BaseModel::dbTime($to)]);
        }

        if ($request->query('format') === 'csv') {
            $this->audit($request, 'audit.exported', 'audit', null, $request->query('project_id'), ['filters' => $request->only(['action', 'actor', 'from', 'to'])]);
            $rows = $q->orderByDesc('created_at')->limit(50000)->cursor()->map(fn (AuditEvent $e) => $e->toApi());

            return Csv::download('gateway-audit-'.now()->format('Ymd-His').'.csv',
                ['created_at', 'source', 'actor_ref', 'actor_name', 'action', 'target_type', 'target_id', 'project_id', 'metadata', 'request_id'], $rows);
        }

        return $this->page($request, $q, fn (AuditEvent $e) => $e->toApi());
    }
}
