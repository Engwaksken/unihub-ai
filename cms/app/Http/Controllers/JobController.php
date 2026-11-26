<?php

namespace App\Http\Controllers;

use App\Authorization\Permissions;
use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Gateway\GatewayPage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Jobs & queue (metadata only: payloads are never requested or shown) and
 * the project "Jobs" tab.
 */
class JobController extends Controller
{
    public const STATES = ['queued', 'running', 'completed', 'failed', 'cancelled', 'expired'];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $projectId = $this->projectFilter($request, 'job.view');
        [$page, $error] = $this->fetch($request, $filters, $projectId);

        return view('jobs.index', [
            'page' => $page,
            'error' => $error,
            'filters' => $filters,
            'projectId' => $projectId,
            'projects' => $this->projectOptions($request, 'job.view'),
        ]);
    }

    public function forProject(Request $request, string $project): View
    {
        $this->authorizeProject('job.view', $project);
        $filters = $this->filters($request);
        $model = $this->gateway($request)->getProject($project);
        [$page, $error] = $this->fetch($request, $filters, $model->id);

        return view('projects.jobs', [
            'project' => $model,
            'page' => $page,
            'error' => $error,
            'filters' => $filters,
            'projects' => [$model->id => $model],
        ]);
    }

    public function cancel(Request $request, string $job): RedirectResponse
    {
        $this->operableProject($request, $job);
        $result = $this->mutate(fn () => $this->gateway($request)->mutateData('POST', '/jobs/'.rawurlencode(strtolower($job)).'/cancel', [], $this->idempotencyKey($request)));

        return back()->with('status', 'Job '.Str::limit((string) ($result['id'] ?? $job), 13, '…').' is now '.($result['state'] ?? 'cancelled').'.');
    }

    public function retry(Request $request, string $job): RedirectResponse
    {
        $this->operableProject($request, $job);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        $new = $this->mutate(fn () => $this->gateway($request)->mutateData('POST', '/jobs/'.rawurlencode(strtolower($job)).'/retry', ['reason' => trim($data['reason'])], $this->idempotencyKey($request)));

        return back()->with('status', 'Retry queued as job '.($new['id'] ?? '').'.');
    }

    /**
     * The gateway has no GET /jobs/{id}, so the form posts the job's project
     * id. It is checked here (CMS policy) and the gateway re-checks against the
     * job's real project with the per-project roles in the assertion.
     */
    private function operableProject(Request $request, string $job): string
    {
        abort_unless(Str::isUuid($job), 404);
        $projectId = $request->input('project_id');
        abort_unless(is_string($projectId) && Str::isUuid($projectId), 404);
        $this->authorizeProject('job.view', $projectId);
        abort_unless(Gate::allows('job.manage', strtolower($projectId)), 403);

        return strtolower($projectId);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            ...$this->dateRangeRules(),
            'state' => ['nullable', Rule::in(self::STATES)],
            'project_id' => ['nullable', 'uuid'],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: GatewayPage<array<mixed>>|null, 1: \Throwable|null}
     */
    private function fetch(Request $request, array $filters, ?string $projectId): array
    {
        try {
            $page = $this->gateway($request)->list('/jobs', [
                'project_id' => $projectId,
                'state' => $filters['state'] ?? null,
                'from' => $this->dayBoundary($filters['from'] ?? null, false),
                'to' => $this->dayBoundary($filters['to'] ?? null, true),
            ], $filters['cursor'] ?? null, 50);

            // Defence in depth: never show a job outside the user's scope.
            $scope = Permissions::projectScope($request->user(), 'job.view');
            if ($scope !== null) {
                $page = $page->filter(fn (array $j) => in_array(strtolower((string) ($j['project_id'] ?? '')), $scope, true));
            }

            return [$page, null];
        } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
            return [null, $e];
        }
    }
}
