<?php

namespace App\Http\Controllers;

use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Services\CmsSettings;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Settings: gateway policy settings (GET/PATCH /settings, editing owner-only)
 * and CMS-only settings stored in the CMS database (owner and ops admin).
 */
class SettingsController extends Controller
{
    /**
     * Editable gateway policy settings, grouped for the form. Ranges mirror the
     * gateway's validation; the gateway remains the authority and its 422
     * messages are shown on the matching field.
     *
     * @var array<string, array<string, array{label: string, type: string, min: int|float, max: int|float, help?: string}>>
     */
    public const POLICY_FIELDS = [
        'Queue & quotas' => [
            'global_queue_limit' => ['label' => 'Global queued-job limit', 'type' => 'integer', 'min' => 0, 'max' => 100],
            'default_project_queue_limit' => ['label' => 'Default per-project queue limit', 'type' => 'integer', 'min' => 0, 'max' => 100, 'help' => 'Must not exceed the global limit.'],
            'default_rpm_limit' => ['label' => 'Default requests per minute', 'type' => 'integer', 'min' => 0, 'max' => 1000],
            'default_daily_limit' => ['label' => 'Default requests per day', 'type' => 'integer', 'min' => 0, 'max' => 100000],
            'poll_rate_per_minute' => ['label' => 'Job status polls per minute', 'type' => 'integer', 'min' => 1, 'max' => 600],
        ],
        'Requests & inference' => [
            'max_body_bytes' => ['label' => 'Max request body (bytes)', 'type' => 'integer', 'min' => 1024, 'max' => 65536],
            'max_output_tokens' => ['label' => 'Max output tokens', 'type' => 'integer', 'min' => 1, 'max' => 1024],
            'queue_wait_max_seconds' => ['label' => 'Max queue wait (s)', 'type' => 'integer', 'min' => 10, 'max' => 3600],
            'inference_deadline_seconds' => ['label' => 'Inference deadline (s)', 'type' => 'integer', 'min' => 10, 'max' => 600],
            'sync_wait_seconds' => ['label' => 'Chat API wait (s)', 'type' => 'integer', 'min' => 10, 'max' => 170, 'help' => 'How long /gw/v1/chat/completions waits for an answer before replying 504. Keep it below the timeout of the calling apps (Elevate Her: 90 s).'],
        ],
        'Retention & keys' => [
            'result_ttl_hours' => ['label' => 'Result retention (hours)', 'type' => 'integer', 'min' => 1, 'max' => 24],
            'metadata_retention_days' => ['label' => 'Job metadata retention (days)', 'type' => 'integer', 'min' => 1, 'max' => 365],
            'key_rotation_overlap_days' => ['label' => 'Default key rotation overlap (days)', 'type' => 'integer', 'min' => 0, 'max' => 30],
        ],
        'Alerts & telemetry' => [
            'alert_ram_pct' => ['label' => 'RAM alert threshold (%)', 'type' => 'integer', 'min' => 50, 'max' => 100],
            'alert_disk_pct' => ['label' => 'Disk alert threshold (%)', 'type' => 'integer', 'min' => 50, 'max' => 100],
            'alert_swap_pct' => ['label' => 'Swap alert threshold (%)', 'type' => 'integer', 'min' => 10, 'max' => 100, 'help' => 'Swap growth while Ollama runs means the model no longer fits in RAM.'],
            'alert_heartbeat_seconds' => ['label' => 'Worker heartbeat alert after (s)', 'type' => 'integer', 'min' => 10, 'max' => 3600],
            'alert_tls_days' => ['label' => 'TLS expiry alert (days before)', 'type' => 'integer', 'min' => 1, 'max' => 90],
            'telemetry_stale_seconds' => ['label' => 'Telemetry stale after (s)', 'type' => 'integer', 'min' => 30, 'max' => 3600],
        ],
        'Cost' => [
            'monthly_hosting_cost_usd' => ['label' => 'Monthly hosting cost (USD)', 'type' => 'numeric', 'min' => 0, 'max' => 100000, 'help' => 'Used for the per-project cost estimate.'],
        ],
    ];

    public function index(Request $request, CmsSettings $cms): View
    {
        $policy = null;
        $error = null;
        if (Gate::allows('settings.view')) {
            try {
                $policy = $this->gateway($request)->getData('/settings');
            } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
                $error = $e;
            }
        }

        return view('settings.index', [
            'policy' => $policy,
            'error' => $error,
            'fields' => self::POLICY_FIELDS,
            'canEditPolicy' => Gate::allows('settings.policy'),
            'canEditCms' => Gate::allows('settings.cms'),
            'cms' => $cms->all(),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function updatePolicy(Request $request): RedirectResponse
    {
        abort_unless(Gate::allows('settings.policy'), 403);

        $rules = [];
        foreach (self::POLICY_FIELDS as $fields) {
            foreach ($fields as $key => $def) {
                $rules[$key] = ['required', $def['type'], 'min:'.$def['min'], 'max:'.$def['max']];
            }
        }
        $data = $request->validate($rules);

        $client = $this->gateway($request);
        $current = $client->getData('/settings');

        // Send only changed values; the gateway rejects unknown/read-only keys.
        $changes = [];
        foreach ($data as $key => $value) {
            $typed = self::fieldType($key) === 'integer' ? (int) $value : (float) $value;
            $old = $current[$key] ?? null;
            if (! is_numeric($old) || (float) $old !== (float) $typed) {
                $changes[$key] = $typed;
            }
        }

        if ($changes === []) {
            return redirect()->route('settings.index')->with('status', 'No gateway settings changed.');
        }

        $this->mutate(fn () => $client->mutateData('PATCH', '/settings', $changes, $this->idempotencyKey($request)));

        return redirect()->route('settings.index')->with('status', 'Gateway settings saved ('.implode(', ', array_keys($changes)).').');
    }

    public function updateCms(Request $request, CmsSettings $cms): RedirectResponse
    {
        abort_unless(Gate::allows('settings.cms'), 403);

        $data = $request->validateWithBag('cmsSettings', [
            'brand_name' => ['required', 'string', 'max:60'],
            'display_timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'notification_recipients' => ['nullable', 'string', 'max:2000'],
        ]);

        $recipients = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($data['notification_recipients'] ?? '')) ?: []))));
        foreach ($recipients as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages(['notification_recipients' => ["“{$email}” is not a valid e-mail address."]])->errorBag('cmsSettings');
            }
        }
        if (count($recipients) > 20) {
            throw ValidationException::withMessages(['notification_recipients' => ['At most 20 recipients.']])->errorBag('cmsSettings');
        }

        $cms->update([
            'brand_name' => trim($data['brand_name']),
            'display_timezone' => $data['display_timezone'],
            'notification_recipients' => array_map('strtolower', $recipients),
        ], $request->user());

        return redirect()->route('settings.index')->with('status', 'Portal settings saved.');
    }

    private static function fieldType(string $key): string
    {
        foreach (self::POLICY_FIELDS as $fields) {
            if (isset($fields[$key])) {
                return $fields[$key]['type'];
            }
        }

        return 'integer';
    }
}
