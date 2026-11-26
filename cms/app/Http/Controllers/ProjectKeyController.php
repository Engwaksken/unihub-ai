<?php

namespace App\Http\Controllers;

use App\Gateway\Exceptions\GatewayConflictException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Project → API keys tab. The secret of a newly issued key is rendered once,
 * directly in the response to the POST (never redirected, flashed, logged or
 * stored), with Cache-Control: no-store. Re-submitting the same form reuses
 * its Idempotency-Key and the gateway refuses to replay a key issue, so the
 * secret can never be shown a second time.
 */
class ProjectKeyController extends Controller
{
    public function index(Request $request, string $project): View
    {
        $this->authorizeProject('key.view', $project);
        $request->validate(['cursor' => ['nullable', 'string', 'max:512']]);

        $client = $this->gateway($request);
        $model = $client->getProject($project);
        $keys = $client->list('/projects/'.rawurlencode(strtolower($project)).'/keys', [], $request->query('cursor'));

        return view('projects.keys', [
            'project' => $model,
            'keys' => $keys,
            'canManage' => Gate::allows('key.manage', strtolower($project)),
            'cursor' => $request->query('cursor'),
        ]);
    }

    public function store(Request $request, string $project): Response|RedirectResponse
    {
        $this->authorizeProject('key.view', $project);
        abort_unless(Gate::allows('key.manage', strtolower($project)), 403);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:80'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'rotates_key_id' => ['nullable', 'uuid'],
            'overlap_days' => ['nullable', 'integer', 'min:0', 'max:30'],
        ]);

        $body = array_filter([
            'label' => isset($data['label']) ? trim($data['label']) : null,
            'expires_at' => $this->dayBoundary($data['expires_on'] ?? null, true),
            'rotates_key_id' => isset($data['rotates_key_id']) ? strtolower($data['rotates_key_id']) : null,
        ], fn ($v) => $v !== null && $v !== '');
        if (isset($data['rotates_key_id']) && isset($data['overlap_days'])) {
            $body['overlap_days'] = (int) $data['overlap_days'];
        }

        $client = $this->gateway($request);
        try {
            $response = $client->request('POST', '/projects/'.rawurlencode(strtolower($project)).'/keys', $body, $this->idempotencyKey($request));
        } catch (GatewayConflictException $e) {
            $message = $e->errorCode === 'IDEMPOTENCY_REPLAY_UNAVAILABLE'
                ? 'This key was already issued by an earlier submission of the form. For security its secret cannot be shown again; revoke it and issue a new key if the secret was not saved.'
                : $e->userMessage();
            throw ValidationException::withMessages(['form' => [$message]]);
        }

        $key = is_array($response['data'] ?? null) ? $response['data'] : [];
        $secret = is_string($response['secret'] ?? null) ? $response['secret'] : null;
        abort_if($secret === null, 502, 'The gateway did not return the new key.');

        return response()
            ->view('projects.key-revealed', [
                'project' => $client->getProject($project),
                'key' => $key,
                'secret' => $secret,
                'rotated' => isset($body['rotates_key_id']),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function revoke(Request $request, string $project, string $key): RedirectResponse
    {
        $this->authorizeProject('key.view', $project);
        abort_unless(Gate::allows('key.manage', strtolower($project)), 403);

        // The gateway authorises against the key's own project with the
        // per-project roles in the assertion, so a key id from another project
        // is refused there unless the actor may manage that project too.
        abort_unless(Str::isUuid($key), 404);
        $revoked = $this->mutate(fn () => $this->gateway($request)->mutateData('POST', '/keys/'.rawurlencode(strtolower($key)).'/revoke', [], $this->idempotencyKey($request)));

        return redirect()->route('projects.keys', $project)
            ->with('status', 'Key '.($revoked['prefix'] ?? '').' revoked. Requests using it are now rejected.');
    }
}
