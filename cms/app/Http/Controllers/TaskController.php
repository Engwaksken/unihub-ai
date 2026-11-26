<?php

namespace App\Http\Controllers;

use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Task templates and their versions. New versions are drafts; publishing
 * makes a version current and immutable (enforced by the gateway).
 */
class TaskController extends Controller
{
    private const KEY = '/^[a-z][a-z0-9_]{1,63}$/';

    public function index(Request $request): View
    {
        $request->validate(['cursor' => ['nullable', 'string', 'max:512']]);
        $tasks = null;
        $models = [];
        $error = null;
        try {
            $client = $this->gateway($request);
            $tasks = $client->list('/tasks', [], $request->query('cursor'), 50);
            $models = collect(ModelController::allModels($client))->keyBy('id')->all();
        } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
            $error = $e;
        }

        return view('tasks.index', ['tasks' => $tasks, 'models' => $models, 'error' => $error, 'cursor' => $request->query('cursor')]);
    }

    public function create(Request $request): View
    {
        $request->validate(['key' => ['nullable', 'string', 'regex:'.self::KEY]]);

        return view('tasks.create', [
            'models' => ModelController::allModels($this->gateway($request)),
            'key' => $request->query('key'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:'.self::KEY],
            'description' => ['nullable', 'string', 'max:500'],
            'system_template' => ['required', 'string', 'min:10', 'max:4000'],
            'output_cap' => ['required', 'integer', 'min:1', 'max:256'],
            'output_format' => ['required', Rule::in(['text', 'json'])],
            'model_id' => ['required', 'uuid'],
        ], ['key.regex' => 'Task keys use lowercase letters, digits and underscores, starting with a letter (2–64 characters).']);

        $body = [
            'key' => $data['key'],
            'system_template' => $data['system_template'],
            'output_cap' => (int) $data['output_cap'],
            'output_format' => $data['output_format'],
            'model_id' => strtolower($data['model_id']),
        ];
        if (filled($data['description'] ?? null)) {
            $body['description'] = trim($data['description']);
        }

        $task = $this->mutate(fn () => $this->gateway($request)->mutateData('POST', '/tasks', $body, $this->idempotencyKey($request)));
        $versions = is_array($task['versions'] ?? null) ? $task['versions'] : [];
        $latest = max(array_map(fn ($v) => (int) ($v['version'] ?? 0), $versions) ?: [0]);

        return redirect()->route('tasks.index')->with('status', "Draft version {$latest} of {$data['key']} created. Publish it to make it live.");
    }

    public function publish(Request $request, string $key, int $version): RedirectResponse
    {
        abort_unless(preg_match(self::KEY, $key) === 1, 404);

        try {
            $this->mutate(fn () => $this->gateway($request)->mutateData('POST', '/tasks/'.rawurlencode($key).'/versions/'.$version.'/publish', [], $this->idempotencyKey($request)));
        } catch (GatewayException $e) {
            if ($e->status === 409 || $e->errorCode === 'INVALID_STATE') {
                throw ValidationException::withMessages(['form' => [$e->userMessage()]]);
            }
            throw $e;
        }

        return redirect()->route('tasks.index')->with('status', "Version {$version} of {$key} is now published and read-only.");
    }
}
