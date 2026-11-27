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
use Illuminate\Validation\ValidationException;

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
     * Cancel, retry or delete the selected jobs. Cancel/retry go job by job (the
     * gateway checks each job's real project against the assertion; a job outside
     * the user's scope comes back as not found and is skipped). Delete removes
     * finished jobs in one call and is limited to owners and ops admins.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['cancel', 'retry', 'delete'])],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $ids = $this->selectedIds($request);
        $client = $this->gateway($request);
        $short = fn (string $id) => 'Job '.substr($id, 0, 8).'…';

        if ($data['action'] === 'delete') {
            abort_unless(Gate::allows('job.delete'), 403);
            $result = $this->mutate(fn () => $client->mutateData('POST', '/jobs/delete', ['ids' => $ids], $this->idempotencyKey($request)));
            $deleted = count($result['deleted'] ?? []);
            $skipped = array_map(fn (array $s) => $short((string) ($s['id'] ?? '')).': '.(($s['reason'] ?? '') === 'not_finished'
                ? 'still queued or running (cancel it first, or wait until it finishes)'
                : 'not found or outside your projects'), $result['skipped'] ?? []);

            return $this->bulkResult("Deleted {$deleted} ".Str::plural('job', $deleted).'. Usage totals are unchanged.', $skipped);
        }

        abort_unless(Gate::allows('job.manage'), 403);
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($data['action'] === 'retry' && mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['form' => ['Give a reason for the retry (at least 3 characters); it is recorded in the audit log.']]);
        }

        $cancel = $data['action'] === 'cancel';
        [$done, $skipped] = $this->eachRow($ids, $short, fn (string $id) => $client->mutateData(
            'POST', '/jobs/'.$id.($cancel ? '/cancel' : '/retry'), $cancel ? [] : ['reason' => $reason], $this->rowIdempotencyKey($request, $id),
        ));

        return $this->bulkResult($cancel
            ? "Cancelled {$done} ".Str::plural('job', $done).'.'
            : "Queued {$done} ".Str::plural('retry', $done).'.', $skipped);
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
