<?php

namespace App\Http\Controllers;

use App\Gateway\Data\Project;
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
 *
 * Daily/weekly/monthly reports, filterable by organization, project, API key
 * and model (audit G7). Filters only narrow what the gateway already scopes to
 * the signed-in user, so a guessed id returns nothing rather than more.
 */
class UsageController extends Controller
{
    public const GROUPS = [
        'day' => 'Day', 'week' => 'Week', 'month' => 'Month',
        'organization' => 'Organization', 'project' => 'Project', 'key' => 'API key', 'model' => 'Model',
    ];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $projectId = $this->projectFilter($request, 'usage.view');
        $projects = $this->projectOptions($request, 'usage.view');

        return view('usage.index', $this->report($request, $filters, $projectId) + [
            'projects' => $projects,
            'organizations' => $this->organizationOptions($projects),
            'keys' => $this->keyOptions($request, $projectId),
            'models' => $this->modelOptions($request),
        ]);
    }

    public function forProject(Request $request, string $project): View
    {
        $this->authorizeProject('usage.view', $project);
        $filters = $this->filters($request, ['day', 'week', 'month', 'key', 'model']);
        $model = $this->gateway($request)->getProject($project);

        return view('projects.usage', $this->report($request, $filters, $model->id) + [
            'project' => $model,
            'keys' => $this->keyOptions($request, $model->id),
            'models' => $this->modelOptions($request),
        ]);
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

        Audit::record('usage.exported', metadata: array_filter([
            'group_by' => $filters['group_by'], 'from' => $filters['from'], 'to' => $filters['to'],
            'organization_id' => $filters['organization_id'] ?? null, 'api_key_id' => $filters['api_key_id'] ?? null, 'model' => $filters['model'] ?? null,
        ], fn ($v) => $v !== null), projectUuid: $projectId);

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
    private function filters(Request $request, ?array $groups = null): array
    {
        $filters = $request->validate([
            ...$this->dateRangeRules(),
            'group_by' => ['nullable', Rule::in($groups ?? array_keys(self::GROUPS))],
            'project_id' => ['nullable', 'uuid'],
            'organization_id' => ['nullable', 'uuid'],
            'api_key_id' => ['nullable', 'uuid'],
            'model' => ['nullable', 'string', 'max:120'],
        ]);
        foreach (['organization_id', 'api_key_id'] as $id) {
            if (isset($filters[$id])) {
                $filters[$id] = strtolower($filters[$id]);
            }
        }
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
            'organization_id' => $filters['organization_id'] ?? null,
            'api_key_id' => $filters['api_key_id'] ?? null,
            'model' => $filters['model'] ?? null,
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

    /**
     * Organizations of the projects the user can see (no extra gateway call).
     *
     * @param  array<string, Project>  $projects
     * @return array<string, string> uuid => name
     */
    private function organizationOptions(array $projects): array
    {
        $orgs = [];
        foreach ($projects as $p) {
            if ($p->organizationId !== null) {
                $orgs[strtolower($p->organizationId)] = $p->organizationName ?? $p->organizationId;
            }
        }
        asort($orgs);

        return $orgs;
    }

    /**
     * A project's keys, only once a project is chosen and the user may see its keys.
     *
     * @return array<string, string> uuid => label
     */
    private function keyOptions(Request $request, ?string $projectId): array
    {
        if ($projectId === null || ! Gate::allows('key.view', $projectId)) {
            return [];
        }
        try {
            $page = $this->gateway($request)->list('/projects/'.rawurlencode($projectId).'/keys', [], null, 100);
        } catch (GatewayUnavailableException|GatewayAuthenticationException) {
            return [];
        }
        $keys = [];
        foreach ($page->items as $k) {
            if (isset($k['id'])) {
                $keys[strtolower((string) $k['id'])] = trim(($k['label'] ?? 'Key').' ('.($k['prefix'] ?? '').')').(($k['status'] ?? 'active') !== 'active' ? ' · '.$k['status'] : '');
            }
        }

        return $keys;
    }

    /** @return list<string> model tags */
    private function modelOptions(Request $request): array
    {
        if (! Gate::allows('model.view')) {
            return [];
        }
        try {
            $page = $this->gateway($request)->list('/models', [], null, 100);
        } catch (GatewayUnavailableException|GatewayAuthenticationException) {
            return [];
        }
        $tags = array_values(array_unique(array_filter(array_map(fn (array $m) => (string) ($m['tag'] ?? ''), $page->items))));
        sort($tags);

        return $tags;
    }
}
