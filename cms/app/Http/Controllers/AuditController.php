<?php

namespace App\Http\Controllers;

use App\Authorization\Permissions;
use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Models\AuditEvent;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Merged audit view (ADR D4): CMS audit events plus gateway GET /audit,
 * each row labelled with its source. Project managers and auditors only see
 * events for their assigned projects on both sides.
 *
 * Paging: each source pages on its own. With "all sources" the newest
 * MERGED_LIMIT events of each source are merged by time; choose one source to
 * page further back.
 */
class AuditController extends Controller
{
    public const MERGED_LIMIT = 50;

    /** Unified CSV columns (the gateway's export columns plus ip_address). */
    public const CSV_COLUMNS = ['created_at', 'source', 'actor_ref', 'actor_name', 'action', 'target_type', 'target_id', 'project_id', 'metadata', 'request_id', 'ip_address'];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $projectId = $this->projectFilter($request, 'audit.view');
        $scope = Permissions::projectScope($request->user(), 'audit.view');

        $cmsRows = [];
        $cmsPaginator = null;
        $gatewayRows = [];
        $gatewayNext = null;
        $gatewayError = null;

        if ($filters['source'] !== 'gateway') {
            $query = $this->cmsQuery($filters, $scope, $projectId);
            if ($filters['source'] === 'cms') {
                $cmsPaginator = $query->simplePaginate(self::MERGED_LIMIT)->withQueryString();
                $events = $cmsPaginator->items();
            } else {
                $events = $query->limit(self::MERGED_LIMIT)->get()->all();
            }
            $cmsRows = array_map(fn (AuditEvent $e) => $this->presentCms($e), $events);
        }

        if ($filters['source'] !== 'cms') {
            try {
                $page = $this->gateway($request)->list('/audit', $this->gatewayQuery($filters, $projectId),
                    $filters['source'] === 'gateway' ? ($filters['cursor'] ?? null) : null, self::MERGED_LIMIT);
                $gatewayRows = array_map(fn (array $e) => $this->presentGateway($e), $page->items);
                if ($scope !== null) {
                    $gatewayRows = array_values(array_filter($gatewayRows, fn ($r) => in_array($r['project_id'], $scope, true)));
                }
                $gatewayNext = $filters['source'] === 'gateway' ? $page->nextCursor : null;
            } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
                $gatewayError = $e;
            }
        }

        $rows = array_merge($cmsRows, $gatewayRows);
        usort($rows, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return view('audit.index', [
            'rows' => $rows,
            'filters' => $filters,
            'projectId' => $projectId,
            'scoped' => $scope !== null,
            'cmsPaginator' => $cmsPaginator,
            'gatewayNext' => $gatewayNext,
            'gatewayError' => $gatewayError,
            'projects' => $this->projectOptions($request, 'audit.view'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $projectId = $this->projectFilter($request, 'audit.view');
        $scope = Permissions::projectScope($request->user(), 'audit.view');

        // Fetch the gateway export first so a gateway failure is an error page,
        // not a silently CMS-only file.
        $gatewayBody = null;
        if ($filters['source'] !== 'cms') {
            $gatewayBody = $this->gateway($request)->download('/audit', $this->gatewayQuery($filters, $projectId) + ['format' => 'csv'])->getBody();
        }

        Audit::record('audit.exported', metadata: array_filter([
            'source' => $filters['source'], 'action' => $filters['action'] ?? null, 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null,
        ]), projectUuid: $projectId);

        $cmsQuery = $filters['source'] !== 'gateway' ? $this->cmsQuery($filters, $scope, $projectId) : null;

        return new StreamedResponse(function () use ($cmsQuery, $gatewayBody, $scope) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::CSV_COLUMNS, ',', '"', '');

            $cmsQuery?->limit(50000)->cursor()->each(function (AuditEvent $e) use ($out) {
                $this->writeCsvRow($out, $this->presentCms($e));
            });

            if ($gatewayBody !== null) {
                // Re-read the gateway CSV row by row and map it onto the unified columns.
                $tmp = fopen('php://temp', 'w+');
                while (! $gatewayBody->eof()) {
                    fwrite($tmp, $gatewayBody->read(8192));
                }
                rewind($tmp);
                $header = fgetcsv($tmp, null, ',', '"', '');
                while ($header !== false && ($line = fgetcsv($tmp, null, ',', '"', '')) !== false) {
                    if ($line === [null]) {
                        continue;
                    }
                    $row = array_combine(array_slice($header, 0, count($line)), array_slice($line, 0, count($header)));
                    if ($scope !== null && ! in_array(strtolower((string) ($row['project_id'] ?? '')), $scope, true)) {
                        continue;
                    }
                    $row['source'] = 'gateway';
                    $this->writeCsvRow($out, $row);
                }
                fclose($tmp);
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="audit-'.$filters['source'].'-'.now()->format('Ymd-His').'.csv"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $filters = $request->validate([
            ...$this->dateRangeRules(),
            'source' => ['nullable', Rule::in(['all', 'cms', 'gateway'])],
            'action' => ['nullable', 'string', 'max:64', 'regex:/^[a-z_.]+$/'],
            'project_id' => ['nullable', 'uuid'],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);
        $filters['source'] ??= 'all';

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>|null  $scope
     * @return Builder<AuditEvent>
     */
    private function cmsQuery(array $filters, ?array $scope, ?string $projectId): Builder
    {
        $from = $this->dayBoundary($filters['from'] ?? null, false);
        $to = $this->dayBoundary($filters['to'] ?? null, true);

        return AuditEvent::query()
            ->visibleTo($scope)
            ->when($projectId, fn ($q, $p) => $q->where('gateway_project_uuid', $p))
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($from, fn ($q, $f) => $q->where('occurred_at', '>=', CarbonImmutable::parse($f)))
            ->when($to, fn ($q, $t) => $q->where('occurred_at', '<=', CarbonImmutable::parse($t)))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function gatewayQuery(array $filters, ?string $projectId): array
    {
        return array_filter([
            'project_id' => $projectId,
            'action' => $filters['action'] ?? null,
            'from' => $this->dayBoundary($filters['from'] ?? null, false),
            'to' => $this->dayBoundary($filters['to'] ?? null, true),
        ], fn ($v) => $v !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCms(AuditEvent $e): array
    {
        return [
            'created_at' => $e->occurred_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'source' => 'cms',
            'actor_ref' => $e->actor_user_id ? 'cms:'.$e->actor_user_id : null,
            'actor_name' => $e->actor_label,
            'action' => $e->action,
            'target_type' => $e->subject_type,
            'target_id' => $e->subject_id,
            'project_id' => $e->gateway_project_uuid,
            'metadata' => $e->metadata,
            'request_id' => $e->request_id,
            'ip_address' => $e->ip_address,
        ];
    }

    /**
     * @param  array<mixed>  $e
     * @return array<string, mixed>
     */
    private function presentGateway(array $e): array
    {
        $str = fn (string $k) => isset($e[$k]) && is_scalar($e[$k]) ? (string) $e[$k] : null;

        return [
            'created_at' => $str('created_at'),
            'source' => 'gateway',
            'actor_ref' => $str('actor_ref'),
            'actor_name' => $str('actor_name'),
            'action' => $str('action'),
            'target_type' => $str('target_type'),
            'target_id' => $str('target_id'),
            'project_id' => $str('project_id') !== null ? strtolower($str('project_id')) : null,
            'metadata' => is_array($e['metadata'] ?? null) ? $e['metadata'] : null,
            'request_id' => $str('request_id'),
            'ip_address' => null,
        ];
    }

    /**
     * @param  resource  $out
     * @param  array<string, mixed>  $row
     */
    private function writeCsvRow($out, array $row): void
    {
        $cells = [];
        foreach (self::CSV_COLUMNS as $column) {
            $value = $row[$column] ?? null;
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $value = $value === null ? '' : (string) $value;
            // Neutralise spreadsheet formula injection (same rule as the gateway).
            if (preg_match('/^[=+\-@\t\r]/', $value)) {
                $value = "'".$value;
            }
            $cells[] = $value;
        }
        fputcsv($out, $cells, ',', '"', '');
    }
}
