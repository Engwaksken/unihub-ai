<?php

namespace App\Http\Controllers\Management;

use App\Http\Middleware\ManagementIdempotency;
use App\Models\ApiKey;
use App\Models\Project;
use App\Services\KeyService;
use App\Services\Settings;
use App\Support\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KeyController extends ManagementController
{
    public function index(Request $request, string $projectId): JsonResponse
    {
        $projectId = $this->uuid($projectId);
        $this->actor($request)->authorize('key.view', $projectId);
        Project::find($projectId) ?? throw ApiException::notFound();

        return $this->page($request, ApiKey::where('project_id', $projectId), fn (ApiKey $k) => $k->toApi());
    }

    public function store(Request $request, string $projectId, KeyService $keys, Settings $settings): JsonResponse
    {
        $projectId = $this->uuid($projectId);
        $this->actor($request)->authorize('key.manage', $projectId);
        $project = Project::find($projectId) ?? throw ApiException::notFound();
        if ($project->status !== 'active') {
            throw ApiException::invalidState('Keys can only be issued for active projects');
        }
        $data = $this->validate($request->json()->all(), [
            'label' => 'nullable|string|max:80',
            'expires_at' => 'nullable|date|after:now',
            'rotates_key_id' => 'nullable|uuid',
            'overlap_days' => 'nullable|integer|min:0|max:30',
        ]);

        $rotates = null;
        if (! empty($data['rotates_key_id'])) {
            $rotates = ApiKey::where('id', strtolower($data['rotates_key_id']))->where('project_id', $projectId)->first()
                ?? throw ApiException::validation('Invalid key to rotate', ['rotates_key_id' => ['not found in this project']]);
            if (! $rotates->isUsable()) {
                throw ApiException::invalidState('Only active keys can be rotated');
            }
        }
        $overlap = (int) ($data['overlap_days'] ?? $settings->int('key_rotation_overlap_days'));
        $expiresAt = isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at'])->utc() : null;

        [$key, $secret] = $keys->issue($project, $this->actor($request)->ref(), $data['label'] ?? null, $expiresAt, $rotates, $overlap);

        $this->audit($request, $rotates ? 'key.rotated' : 'key.issued', 'api_key', $key->id, $projectId, array_filter([
            'prefix' => $key->prefix,
            'expires_at' => $key::iso($key->expires_at),
            'rotated_prefix' => $rotates?->prefix,
            'old_key_expires_at' => $rotates ? $key::iso($rotates->fresh()->expires_at) : null,
        ]));

        return new JsonResponse(['data' => $key->toApi(), 'secret' => $secret], 201, [
            'Cache-Control' => 'no-store',
            ManagementIdempotency::NO_REPLAY_HEADER => '1',
        ]);
    }

    public function revoke(Request $request, string $id, KeyService $keys): JsonResponse
    {
        $key = ApiKey::find($this->uuid($id)) ?? throw ApiException::notFound();
        $this->actor($request)->authorize('key.manage', $key->project_id);
        if ($keys->revoke($key)) {
            $this->audit($request, 'key.revoked', 'api_key', $key->id, $key->project_id, ['prefix' => $key->prefix]);
        }

        return $this->data($key->fresh()->toApi());
    }
}
