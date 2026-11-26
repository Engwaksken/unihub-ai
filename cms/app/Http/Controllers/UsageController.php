<?php

namespace App\Http\Controllers;

use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Support\Audit;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Usage & reports (GET /usage) and the project "Usage" tab. CSV export is the
 * gateway's own `format=csv` output, streamed through unchanged.
 */
class UsageController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $projectId = $this->projectFilter($request, 'usage.view');

        return view('usage.index', $this->report($request, $filters, $projectId) + [
            'projects' => $this->projectOptions($request, 'usage.view'),
        ]);
    }

    public function forProject(Request $request, string $project): View
    {
        $this->authorizeProject('usage.view', $project);
        $filters = $this->filters($request, ['day', 'model']);
        $model = $this->gateway($request)->getProject($project);

        return view('projects.usage', $this->report($request, $filters, $model->id) + ['project' => $model]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $projectId = $this->projectFilter($request, 'usage.view');
        abort_unless(Gate::allows('usage.view'), 403);

        // Resolves before streaming starts, so gateway errors still produce a
        // proper error page instead of a truncated file.
        $psr = $this->gateway($request)->download('/usage', $this->query($filters, $projectId) + ['format' => 'csv']);
        $body = $psr->getBody();

        Audit::record('usage.exported', metadata: [
            'group_by' => $filters['group_by'], 'from' => $filters['from'], 'to' => $filters['to'],
        ], projectUuid: $projectId);

        $name = sprintf('usage-%s-%s-%s%s.csv', $filters['group_by'], str_replace('-', '', $filters['from']), str_replace('-', '', $filters['to']), $projectId ? '-'.substr($projectId, 0, 8) : '');

        return new StreamedResponse(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(8192);
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  list<string>  $groups
     * @return array<string, mixed>
     */
    private function filters(Request $request, array $groups = ['day', 'project', 'model']): array
    {
        $filters = $request->validate([
            ...$this->dateRangeRules(),
            'group_by' => ['nullable', Rule::in($groups)],
            'project_id' => ['nullable', 'uuid'],
        ]);
        $today = CarbonImmutable::now(DisplayTime::zone());
        $filters['from'] ??= $today->subDays(29)->format('Y-m-d');
        $filters['to'] ??= $today->format('Y-m-d');
        $filters['group_by'] ??= 'day';

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function query(array $filters, ?string $projectId): array
    {
        return [
            'from' => $this->dayBoundary($filters['from'], false),
            'to' => $this->dayBoundary($filters['to'], true),
            'group_by' => $filters['group_by'],
            'project_id' => $projectId,
            'tz' => DisplayTime::zone(), // day buckets follow the display time zone
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function report(Request $request, array $filters, ?string $projectId): array
    {
        $rows = null;
        $meta = [];
        $error = null;
        try {
            $body = $this->gateway($request)->request('GET', '/usage', array_filter($this->query($filters, $projectId), fn ($v) => $v !== null));
            $rows = is_array($body['data'] ?? null) ? array_values(array_filter($body['data'], 'is_array')) : [];
            $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
            $error = $e;
        }

        return ['rows' => $rows, 'meta' => $meta, 'error' => $error, 'filters' => $filters, 'projectId' => $projectId];
    }
}
