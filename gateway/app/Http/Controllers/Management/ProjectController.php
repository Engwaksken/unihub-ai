<?php

namespace App\Http\Controllers\Management;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Services\AuditLogger;
use App\Services\Settings;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectController extends ManagementController
{
    /** Field → capability required to change it (docs/permissions.md). */
    private const FIELD_CAPABILITY = [
        'name' => 'project.edit_meta',
        'owner_ref' => 'project.edit_meta',
        'contact_email' => 'project.edit_meta',
        'environment' => 'project.edit_meta',
        'status' => 'project.status',
        'daily_limit' => 'project.quota',
        'rpm_limit' => 'project.quota',
        'queue_limit' => 'project.quota',
        'allowed_tasks' => 'project.allowlist',
    ];

    private const AUDITED = ['name', 'owner_ref', 'contact_email', 'environment', 'status', 'daily_limit', 'rpm_limit', 'queue_limit', 'allowed_tasks'];

    public function index(Request $request): JsonResponse
    {
        $q = Project::query()->with('organization', 'tasks');
        $this->actor($request)->scopeQuery($q, 'project.view', 'id');
        foreach (['organization_id', 'status', 'environment'] as $f) {
            if ($v = $request->query($f)) {
                $q->where($f, $v);
            }
        }
        if ($search = trim((string) $request->query('q'))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('slug', 'like', $like));
        }

        return $this->page($request, $q, fn (Project $p) => $this->present($p));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->data($this->present($this->find($request, $id)));
    }

    public function store(Request $request, Settings $settings): JsonResponse
    {
        $actor = $this->actor($request);
        $actor->authorize('project.create');
        $data = $this->validate($request->json()->all(), [
            'organization_id' => 'required|uuid',
            'slug' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{2,62}$/', 'unique:projects,slug'],
            'name' => 'required|string|min:2|max:120',
            'owner_ref' => 'nullable|string|max:120',
            'contact_email' => 'nullable|email:rfc|max:190',
            'environment' => 'required|in:staging,production',
            'daily_limit' => 'sometimes|integer|min:0|max:100000',
            'rpm_limit' => 'sometimes|integer|min:0|max:1000',
            'queue_limit' => 'sometimes|integer|min:0|max:'.$settings->int('global_queue_limit'),
            'allowed_tasks' => 'sometimes|array',
            'allowed_tasks.*' => 'string|distinct',
        ]);
        // Non-default quotas at creation need the quota capability.
        foreach (['daily_limit', 'rpm_limit', 'queue_limit'] as $f) {
            if (array_key_exists($f, $data) && ! $actor->can('project.quota')) {
                throw ApiException::forbidden("Not permitted to set {$f}");
            }
        }
        if (array_key_exists('allowed_tasks', $data)) {
            $actor->authorize('project.allowlist');
        }
        $org = Organization::find(strtolower($data['organization_id']));
        if ($org === null || $org->status !== 'active') {
            throw ApiException::validation('Invalid organization', ['organization_id' => ['must be an active organization']]);
        }
        $taskIds = $this->resolveTasks($data['allowed_tasks'] ?? []);

        $project = DB::transaction(function () use ($data, $settings, $taskIds) {
            $project = Project::create([
                'organization_id' => strtolower($data['organization_id']),
                'slug' => $data['slug'],
                'name' => $data['name'],
                'owner_ref' => $data['owner_ref'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'environment' => $data['environment'],
                'status' => 'active',
                'daily_limit' => $data['daily_limit'] ?? $settings->int('default_daily_limit'),
                'rpm_limit' => $data['rpm_limit'] ?? $settings->int('default_rpm_limit'),
                'queue_limit' => $data['queue_limit'] ?? $settings->int('default_project_queue_limit'),
            ]);
            $project->tasks()->sync($taskIds);

            return $project;
        });
        $project->load('organization', 'tasks');
        $this->audit($request, 'project.created', 'project', $project->id, $project->id, ['slug' => $project->slug, 'state' => $this->snapshot($project)]);

        return $this->data($this->present($project), 201);
    }

    public function update(Request $request, string $id, Settings $settings): JsonResponse
    {
        $actor = $this->actor($request);
        $project = $this->find($request, $id);
        $input = $request->json()->all();

        $unknown = array_diff(array_keys($input), array_keys(self::FIELD_CAPABILITY));
        if ($unknown !== []) {
            throw ApiException::validation('Unknown or read-only fields', array_fill_keys(array_values($unknown), ['not editable']));
        }
        foreach (array_keys($input) as $field) {
            if (! $actor->can(self::FIELD_CAPABILITY[$field], $project->id)) {
                throw ApiException::forbidden("Not permitted to change {$field}");
            }
        }
        $data = $this->validate($input, [
            'name' => 'sometimes|string|min:2|max:120',
            'owner_ref' => 'sometimes|nullable|string|max:120',
            'contact_email' => 'sometimes|nullable|email:rfc|max:190',
            'environment' => 'sometimes|in:staging,production',
            'status' => ['sometimes', Rule::in(Project::STATUSES)],
            'daily_limit' => 'sometimes|integer|min:0|max:100000',
            'rpm_limit' => 'sometimes|integer|min:0|max:1000',
            'queue_limit' => 'sometimes|integer|min:0|max:'.$settings->int('global_queue_limit'),
            'allowed_tasks' => 'sometimes|array',
            'allowed_tasks.*' => 'string|distinct',
        ]);
        if ($project->status === 'archived' && ($data['status'] ?? 'archived') === 'archived' && count($data) > 0) {
            throw ApiException::invalidState('Archived projects are read-only; change status first');
        }

        $before = $this->snapshot($project);
        $taskIds = array_key_exists('allowed_tasks', $data) ? $this->resolveTasks($data['allowed_tasks']) : null;
        DB::transaction(function () use ($project, $data, $taskIds) {
            $project->fill(collect($data)->except('allowed_tasks')->all())->save();
            if ($taskIds !== null) {
                $project->tasks()->sync($taskIds);
            }
        });
        $project->load('organization', 'tasks');
        $changes = AuditLogger::diff($before, $this->snapshot($project), self::AUDITED);
        if ($changes) {
            $action = array_key_exists('status', $changes) ? 'project.status_changed' : 'project.updated';
            $this->audit($request, $action, 'project', $project->id, $project->id, $changes);
        }

        return $this->data($this->present($project));
    }

    private function find(Request $request, string $id): Project
    {
        $id = $this->uuid($id);
        $this->actor($request)->authorize('project.view', $id);

        return Project::with('organization', 'tasks')->find($id) ?? throw ApiException::notFound();
    }

    /** @return string[] task ids */
    private function resolveTasks(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $tasks = Task::whereIn('key', $keys)->pluck('id', 'key');
        $missing = array_diff($keys, $tasks->keys()->all());
        if ($missing !== []) {
            throw ApiException::validation('Unknown tasks', ['allowed_tasks' => ['unknown: '.implode(', ', $missing)]]);
        }

        return $tasks->values()->all();
    }

    private function snapshot(Project $p): array
    {
        $s = $p->only(['name', 'owner_ref', 'contact_email', 'environment', 'status', 'daily_limit', 'rpm_limit', 'queue_limit']);
        $s['allowed_tasks'] = $p->tasks->pluck('key')->sort()->values()->all();

        return $s;
    }

    public static function present(Project $p): array
    {
        return [
            'id' => $p->id,
            'organization' => ['id' => $p->organization->id, 'name' => $p->organization->name],
            'slug' => $p->slug,
            'name' => $p->name,
            'owner_ref' => $p->owner_ref,
            'contact_email' => $p->contact_email,
            'environment' => $p->environment,
            'status' => $p->status,
            'daily_limit' => $p->daily_limit,
            'rpm_limit' => $p->rpm_limit,
            'queue_limit' => $p->queue_limit,
            'allowed_tasks' => $p->tasks->pluck('key')->sort()->values()->all(),
            'created_at' => $p::iso($p->created_at),
            'updated_at' => $p::iso($p->updated_at),
        ];
    }
}
