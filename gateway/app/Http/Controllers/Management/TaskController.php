<?php

namespace App\Http\Controllers\Management;

use App\Models\AiModel;
use App\Models\Task;
use App\Models\TaskVersion;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Versioned task templates; published versions are immutable (PRD §03/§07). */
class TaskController extends ManagementController
{
    public function index(Request $request): JsonResponse
    {
        $this->actor($request)->authorize('task.view');

        return $this->page($request, Task::query()->with('versions'), fn (Task $t) => $this->present($t));
    }

    /** Creates the task if new, and a new DRAFT version. */
    public function store(Request $request): JsonResponse
    {
        $this->actor($request)->authorize('task.manage');
        $data = $this->validate($request->json()->all(), [
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'description' => 'nullable|string|max:500',
            'system_template' => 'required|string|min:10|max:4000',
            'output_cap' => 'required|integer|min:1|max:256',
            'output_format' => 'sometimes|in:text,json',
            'model_id' => 'required|uuid',
        ]);
        $model = AiModel::find(strtolower($data['model_id']));
        if ($model === null) {
            throw ApiException::validation('Unknown model', ['model_id' => ['not found']]);
        }

        [$task, $version] = DB::transaction(function () use ($data, $request) {
            $task = Task::firstOrCreate(['key' => $data['key']], ['description' => $data['description'] ?? null]);
            if (array_key_exists('description', $data)) {
                $task->update(['description' => $data['description']]);
            }
            $next = (int) TaskVersion::where('task_id', $task->id)->max('version') + 1;
            $version = TaskVersion::create([
                'task_id' => $task->id,
                'version' => $next,
                'system_template' => $data['system_template'],
                'output_cap' => $data['output_cap'],
                'output_format' => $data['output_format'] ?? 'text',
                'model_id' => strtolower($data['model_id']),
                'created_by' => $this->actor($request)->ref(),
            ]);

            return [$task, $version];
        });
        $this->audit($request, 'task.version_created', 'task', $task->id, null, ['key' => $task->key, 'version' => $version->version, 'template_sha256' => hash('sha256', $version->system_template)]);

        return $this->data($this->present($task->load('versions')), 201);
    }

    public function publish(Request $request, string $key, int $version): JsonResponse
    {
        $this->actor($request)->authorize('task.manage');
        $task = Task::where('key', $key)->first() ?? throw ApiException::notFound();
        $v = TaskVersion::where('task_id', $task->id)->where('version', $version)->first() ?? throw ApiException::notFound();
        if ($v->published_at !== null) {
            throw ApiException::invalidState('Version already published');
        }
        if (! $v->model?->enabled) {
            throw ApiException::invalidState('Task model must be enabled before publishing');
        }
        $v->published_at = now();
        $v->save();
        $this->audit($request, 'task.version_published', 'task', $task->id, null, ['key' => $task->key, 'version' => $v->version]);

        return $this->data($this->present($task->load('versions')));
    }

    private function present(Task $t): array
    {
        $current = $t->versions->whereNotNull('published_at')->sortByDesc('version')->first();

        return [
            'id' => $t->id,
            'key' => $t->key,
            'description' => $t->description,
            'current_version' => $current?->version,
            'versions' => $t->versions->map->toApi()->values()->all(),
            'created_at' => $t::iso($t->created_at),
        ];
    }
}
