<?php

namespace App\Http\Controllers;

use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Services\GatewayReachability;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Server & alerts: telemetry (GET /health), alerts (GET /alerts, POST ack)
 * and admission control (PUT /admission).
 */
class HealthController extends Controller
{
    public const ALERT_STATUSES = ['open', 'acknowledged', 'resolved'];

    public function index(Request $request, GatewayReachability $reachability): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', ...self::ALERT_STATUSES])],
            'hours' => ['nullable', 'integer', Rule::in([6, 24, 72, 168])],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);
        $filters['status'] ??= 'active';
        $filters['hours'] = (int) ($filters['hours'] ?? 6);

        $health = null;
        $alerts = null;
        $admission = null;
        $error = null;
        try {
            $client = $this->gateway($request);
            $health = $client->cachedData('/health', ['hours' => $filters['hours']]);
            $alerts = $client->list('/alerts', [
                'status' => $filters['status'] === 'active' ? 'open,acknowledged' : $filters['status'],
            ], $filters['cursor'] ?? null, 50);

            // Admission state: from /settings for roles that may read settings,
            // otherwise from the (scoped) summary, which also reports it.
            $source = Gate::allows('settings.view') ? $client->getData('/settings') : $client->cachedData('/summary');
            $admission = is_string($source['admission'] ?? null) ? $source['admission'] : null;
        } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
            $error = $e;
        }

        return view('health.index', [
            'health' => $health,
            'alerts' => $alerts,
            'admission' => $admission,
            'error' => $error,
            'filters' => $filters,
            'local' => $reachability->state(),
            'localAlerting' => $reachability->isAlerting(),
        ]);
    }

    public function acknowledge(Request $request, string $alert): RedirectResponse
    {
        abort_unless(Str::isUuid($alert), 404);

        $result = $this->mutate(fn () => $this->gateway($request)->mutateData('POST', '/alerts/'.rawurlencode(strtolower($alert)).'/ack', [], $this->idempotencyKey($request)));

        return back()->with('status', 'Alert '.($result['type'] ?? '').' acknowledged.');
    }

    public function admission(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'state' => ['required', Rule::in(['open', 'paused'])],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        $result = $this->mutate(fn () => $this->gateway($request)->mutateData('PUT', '/admission', [
            'state' => $data['state'],
            'reason' => trim($data['reason']),
        ], $this->idempotencyKey($request)));

        $state = ($result['admission'] ?? $data['state']) === 'paused' ? 'paused: new jobs are refused' : 'open: new jobs are accepted';

        return redirect()->route('health.index')->with('status', "Admission is now {$state}.");
    }
}
