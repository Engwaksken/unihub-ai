<?php

namespace App\Http\Controllers;

use App\Gateway\Data\Organization;
use App\Gateway\Data\Project;
use App\Gateway\GatewayClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    private const TASK_KEY = '/^[a-z][a-z0-9_]{1,63}$/';

    public function index(): View
    {
        return view('projects.index');
    }

    public function create(Request $request): View
    {
        return view('projects.create', [
            'organizations' => $this->activeOrganizations($this->gateway($request)),
            'canSetQuotas' => Gate::allows('project.change_quotas'),
            'canSetAllowlist' => Gate::allows('project.change_allowlist'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $canSetQuotas = Gate::allows('project.change_quotas');
        $canSetAllowlist = Gate::allows('project.change_allowlist');

        $rules = [
            'organization_id' => ['required', 'uuid'],
            'slug' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{2,62}$/'],
            'name' => ['required', 'string', 'max:120'],
            'owner_ref' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email:rfc', 'max:190'],
            'environment' => ['required', Rule::in(Project::ENVIRONMENTS)],
        ];
        if ($canSetQuotas) {
            $rules += $this->quotaRules(required: false);
        }
        if ($canSetAllowlist) {
            $rules['allowed_tasks'] = ['nullable', 'string', 'max:2000'];
        }

        $data = $request->validate($rules, [
            'slug.regex' => 'The slug must be 3–63 lowercase letters, digits or hyphens and start with a letter or digit.',
        ]);

        $body = [
            'organization_id' => strtolower($data['organization_id']),
            'slug' => $data['slug'],
            'name' => trim($data['name']),
            'environment' => $data['environment'],
        ];
        foreach (['owner_ref', 'contact_email'] as $field) {
            if (filled($data[$field] ?? null)) {
                $body[$field] = trim($data[$field]);
            }
        }
        if ($canSetQuotas) {
            foreach (['daily_limit', 'rpm_limit', 'queue_limit'] as $field) {
                if (isset($data[$field]) && $data[$field] !== '') {
                    $body[$field] = (int) $data[$field];
                }
            }
        }
        if ($canSetAllowlist) {
            $body['allowed_tasks'] = $this->parseTasks($data['allowed_tasks'] ?? '');
        }

        $project = $this->mutate(fn () => $this->gateway($request)->createProject($body, $this->idempotencyKey($request)));

        return redirect()->route('projects.settings', $project->id)->with('status', "Project “{$project->name}” created.");
    }

    public function show(string $project): RedirectResponse
    {
        $this->authorizeProject('project.view', $project);

        // Settings is the only tab built so far.
        return redirect()->route('projects.settings', $project);
    }

    public function settings(Request $request, string $project): View
    {
        $this->authorizeProject('project.view', $project);

        $model = $this->gateway($request)->getProject($project);

        return view('projects.settings', [
            'project' => $model,
            'can' => $this->fieldPermissions($project),
        ]);
    }

    public function update(Request $request, string $project): RedirectResponse
    {
        $this->authorizeProject('project.view', $project);
        $can = $this->fieldPermissions($project);
        abort_unless($can['metadata'] || $can['quotas'] || $can['allowlist'], 403);

        $rules = [];
        if ($can['metadata']) {
            $rules += [
                'name' => ['required', 'string', 'max:120'],
                'owner_ref' => ['nullable', 'string', 'max:120'],
                'contact_email' => ['nullable', 'email:rfc', 'max:190'],
                'environment' => ['required', Rule::in(Project::ENVIRONMENTS)],
            ];
        }
        if ($can['quotas']) {
            $rules += $this->quotaRules(required: true);
        }
        if ($can['allowlist']) {
            $rules['allowed_tasks'] = ['nullable', 'string', 'max:2000'];
        }

        $data = $request->validate($rules);
        $client = $this->gateway($request);
        $current = $client->getProject($project);

        // Send only fields the actor may change and that actually changed;
        // the gateway rejects fields outside the actor's permissions (403).
        $changes = [];
        if ($can['metadata']) {
            $this->diff($changes, 'name', trim($data['name']), $current->name);
            // Cleared optional fields are sent as null.
            $this->diff($changes, 'owner_ref', trim((string) ($data['owner_ref'] ?? '')) ?: null, $current->ownerRef ?: null);
            $this->diff($changes, 'contact_email', trim((string) ($data['contact_email'] ?? '')) ?: null, $current->contactEmail ?: null);
            $this->diff($changes, 'environment', $data['environment'], $current->environment);
        }
        if ($can['quotas']) {
            $this->diff($changes, 'daily_limit', (int) $data['daily_limit'], $current->dailyLimit);
            $this->diff($changes, 'rpm_limit', (int) $data['rpm_limit'], $current->rpmLimit);
            $this->diff($changes, 'queue_limit', (int) $data['queue_limit'], $current->queueLimit);
        }
        if ($can['allowlist']) {
            $tasks = $this->parseTasks($data['allowed_tasks'] ?? '');
            $existing = $current->allowedTasks;
            sort($existing);
            $sorted = $tasks;
            sort($sorted);
            if ($sorted !== $existing) {
                $changes['allowed_tasks'] = $tasks;
            }
        }

        if ($changes === []) {
            return redirect()->route('projects.settings', $project)->with('status', 'No changes to save.');
        }

        $this->mutate(fn () => $client->updateProject($project, $changes, $this->idempotencyKey($request)));

        return redirect()->route('projects.settings', $project)->with('status', 'Project settings saved.');
    }

    public function updateStatus(Request $request, string $project): RedirectResponse
    {
        $this->authorizeProject('project.view', $project);
        abort_unless(Gate::allows('project.change_status', strtolower($project)), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(Project::STATUSES)],
        ]);

        $updated = $this->mutate(fn () => $this->gateway($request)->updateProject($project, ['status' => $data['status']], $this->idempotencyKey($request)));

        return redirect()->route('projects.settings', $project)->with('status', "Project status changed to {$updated->status}.");
    }

    /**
     * @return array{metadata: bool, quotas: bool, allowlist: bool, status: bool}
     */
    private function fieldPermissions(string $project): array
    {
        $uuid = strtolower($project);

        return [
            'metadata' => Gate::allows('project.edit_metadata', $uuid),
            'quotas' => Gate::allows('project.change_quotas', $uuid),
            'allowlist' => Gate::allows('project.change_allowlist', $uuid),
            'status' => Gate::allows('project.change_status', $uuid),
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function quotaRules(bool $required): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            'daily_limit' => [$presence, 'integer', 'min:0', 'max:1000000'],
            'rpm_limit' => [$presence, 'integer', 'min:0', 'max:10000'],
            'queue_limit' => [$presence, 'integer', 'min:0', 'max:8'],
        ];
    }

    /**
     * Turns "daily_plan, summary" (comma or newline separated) into a list of
     * task keys, validating each one.
     *
     * @return list<string>
     */
    private function parseTasks(string $input): array
    {
        $keys = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $input) ?: []), fn ($k) => $k !== '')));

        foreach ($keys as $key) {
            if (! preg_match(self::TASK_KEY, $key)) {
                throw ValidationException::withMessages([
                    'allowed_tasks' => ["“{$key}” is not a valid task key (lowercase letters, digits and underscores, starting with a letter)."],
                ]);
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function diff(array &$changes, string $field, mixed $new, mixed $old): void
    {
        if ($new !== $old) {
            $changes[$field] = $new;
        }
    }

    /**
     * @return list<Organization>
     */
    private function activeOrganizations(GatewayClient $client): array
    {
        $organizations = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $client->listOrganizations(['status' => 'active'], $cursor, 100);
            array_push($organizations, ...$page->items);
            $cursor = $page->nextCursor;
        } while ($cursor !== null && ++$pages < 10);

        usort($organizations, fn (Organization $a, Organization $b) => strcasecmp($a->name, $b->name));

        return $organizations;
    }
}
