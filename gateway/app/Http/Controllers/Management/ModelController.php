<?php

namespace App\Http\Controllers\Management;

use App\Models\AiModel;
use App\Services\AuditLogger;
use App\Services\ModelInventory;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ModelController extends ManagementController
{
    public function index(Request $request): JsonResponse
    {
        $this->actor($request)->authorize('model.view');

        return $this->page($request, AiModel::query(), fn (AiModel $m) => $m->toApi());
    }

    /** Re-read installed models from Ollama. Never pulls. */
    public function refresh(Request $request, ModelInventory $inventory): JsonResponse
    {
        $this->actor($request)->authorize('model.manage');
        try {
            $result = $inventory->sync();
        } catch (Throwable) {
            throw new ApiException('SERVICE_UNAVAILABLE', 503, 'Inference service unreachable', [], 30);
        }
        $this->audit($request, 'models.refreshed', 'model', null, null, $result);

        return $this->data(AiModel::orderBy('tag')->get()->map->toApi()->all());
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->actor($request)->authorize('model.manage');
        $model = AiModel::find($this->uuid($id)) ?? throw ApiException::notFound();
        $data = $this->validate($request->json()->all(), [
            'enabled' => 'sometimes|boolean',
            'licence' => 'sometimes|nullable|string|max:120',
        ]);
        if (($data['enabled'] ?? false) === true) {
            if (! $model->installed) {
                throw ApiException::invalidState('Model is not installed on the host');
            }
            if (empty($data['licence'] ?? $model->licence)) {
                throw ApiException::validation('Licence must be recorded before enabling', ['licence' => ['required to enable']]);
            }
            if (AiModel::where('enabled', true)->where('id', '!=', $model->id)->exists()) {
                // PRD §08: one loaded model on the 4 GB host.
                throw ApiException::invalidState('Only one model may be enabled; disable the current model first');
            }
        }
        $before = $model->only(['enabled', 'licence']);
        $model->fill($data)->save();
        $this->audit($request, 'model.updated', 'model', $model->id, null, AuditLogger::diff($before, $model->only(['enabled', 'licence']), ['enabled', 'licence']) + ['tag' => $model->tag]);

        return $this->data($model->toApi());
    }
}
