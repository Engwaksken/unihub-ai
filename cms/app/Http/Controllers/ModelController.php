<?php

namespace App\Http\Controllers;

use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Gateway\GatewayClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Installed models (read from Ollama by the gateway). There is deliberately
 * no pull, delete or shell action: the gateway only re-reads what is installed.
 */
class ModelController extends Controller
{
    public function index(Request $request): View
    {
        $models = null;
        $error = null;
        try {
            $models = self::allModels($this->gateway($request));
        } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
            $error = $e;
        }

        return view('models.index', ['models' => $models, 'error' => $error]);
    }

    public function refresh(Request $request): RedirectResponse
    {
        try {
            $models = $this->mutate(fn () => $this->gateway($request)->mutateData('POST', '/models/refresh', [], $this->idempotencyKey($request)));
        } catch (GatewayUnavailableException $e) {
            if ($e->status === 503) {
                return back()->withErrors(['form' => 'The gateway could not reach the inference service (Ollama) to read installed models. Try again later.']);
            }
            throw $e;
        }

        return redirect()->route('models.index')->with('status', 'Installed models re-read from the host ('.count($models).' found).');
    }

    public function update(Request $request, string $model): RedirectResponse
    {
        abort_unless(Str::isUuid($model), 404);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'licence' => ['nullable', 'string', 'max:120', 'required_if:enabled,1'],
        ], ['licence.required_if' => 'Record the model licence before enabling it.']);

        $body = ['enabled' => (bool) $data['enabled']];
        if (array_key_exists('licence', $data) && $data['licence'] !== null) {
            $body['licence'] = trim($data['licence']);
        }

        try {
            $updated = $this->mutate(fn () => $this->gateway($request)->mutateData('PATCH', '/models/'.rawurlencode(strtolower($model)), $body, $this->idempotencyKey($request)));
        } catch (GatewayException $e) {
            if ($e->status === 409 || $e->errorCode === 'INVALID_STATE') {
                throw ValidationException::withMessages(['form' => [$e->userMessage()]]);
            }
            throw $e;
        }

        return redirect()->route('models.index')->with('status', 'Model '.($updated['tag'] ?? '').' is now '.(! empty($updated['enabled']) ? 'enabled' : 'disabled').'.');
    }

    /**
     * @return list<array<mixed>>
     */
    public static function allModels(GatewayClient $client): array
    {
        $models = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $client->list('/models', [], $cursor, 100);
            array_push($models, ...$page->items);
            $cursor = $page->nextCursor;
        } while ($cursor !== null && ++$pages < 5);

        usort($models, fn ($a, $b) => strcmp((string) ($a['tag'] ?? ''), (string) ($b['tag'] ?? '')));

        return $models;
    }
}
