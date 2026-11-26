<?php

namespace App\Http\Controllers\Management;

use App\Models\Organization;
use App\Services\AuditLogger;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends ManagementController
{
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $actor->authorize('org.view');
        $q = Organization::query()->withCount('projects');
        if (! $actor->isGlobal('org.view')) {
            $q->whereIn('id', fn ($sub) => $sub->select('organization_id')->from('projects')->whereIn('id', $actor->projectsWith('org.view') ?: ['__none__']));
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($search = trim((string) $request->query('q'))) {
            $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%');
        }

        return $this->page($request, $q, fn (Organization $o) => $this->present($o));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $org = $this->find($request, $id);

        return $this->data($this->present($org->loadCount('projects')));
    }

    public function store(Request $request): JsonResponse
    {
        $this->actor($request)->authorize('org.manage');
        $data = $this->validate($request->json()->all(), ['name' => 'required|string|min:2|max:120']);
        $org = Organization::create(['name' => $data['name'], 'status' => 'active']);
        $this->audit($request, 'organization.created', 'organization', $org->id, null, ['name' => $org->name]);

        return $this->data($this->present($org->loadCount('projects')), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->actor($request)->authorize('org.manage');
        $org = $this->find($request, $id);
        $data = $this->validate($request->json()->all(), [
            'name' => 'sometimes|string|min:2|max:120',
            'status' => 'sometimes|in:active,archived',
        ]);
        $before = $org->only(['name', 'status']);
        $org->fill($data)->save();
        $changes = AuditLogger::diff($before, $org->only(['name', 'status']), ['name', 'status']);
        if ($changes) {
            $this->audit($request, 'organization.updated', 'organization', $org->id, null, $changes);
        }

        return $this->data($this->present($org->loadCount('projects')));
    }

    private function find(Request $request, string $id): Organization
    {
        $actor = $this->actor($request);
        $actor->authorize('org.view');
        $org = Organization::find($this->uuid($id)) ?? throw ApiException::notFound();
        if (! $actor->isGlobal('org.view') && ! $org->projects()->whereIn('id', $actor->projectsWith('org.view') ?: ['__none__'])->exists()) {
            throw ApiException::notFound();
        }

        return $org;
    }

    private function present(Organization $o): array
    {
        return [
            'id' => $o->id,
            'name' => $o->name,
            'status' => $o->status,
            'project_count' => (int) ($o->projects_count ?? 0),
            'created_at' => $o::iso($o->created_at),
            'updated_at' => $o::iso($o->updated_at),
        ];
    }
}
