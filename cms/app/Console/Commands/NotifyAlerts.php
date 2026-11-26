<?php

namespace App\Console\Commands;

use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Gateway\GatewayClient;
use App\Mail\GatewayAlertMail;
use App\Services\CmsSettings;
use App\Services\GatewayReachability;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Scheduled every minute (routes/console.php). Polls GET /alerts?status=open
 * as the system actor and e-mails each new alert id once to the configured
 * recipients (dedupe in alert_notifications). If the gateway cannot be reached
 * for 2 minutes of consecutive polls, it raises a local "gateway unreachable"
 * alert and e-mails it once per outage, and a recovery notice afterwards.
 */
class NotifyAlerts extends Command
{
    protected $signature = 'cms:notify-alerts';

    protected $description = 'E-mail new gateway alerts and a local alert when the gateway is unreachable';

    public function handle(GatewayReachability $reachability, CmsSettings $settings): int
    {
        $actor = CreateSystemUser::find();
        if ($actor === null || $actor->isDisabled()) {
            $this->warn('No active system actor: run php artisan cms:system-user. Skipping.');

            return self::SUCCESS;
        }

        $recipients = $settings->recipients();

        try {
            $alerts = $this->openAlerts(app(GatewayClient::class)->as($actor));
        } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
            $this->handleUnreachable($reachability, $recipients, $e);

            return self::SUCCESS;
        } catch (GatewayException $e) {
            Log::warning('Alert notifier: gateway returned an error.', ['status' => $e->status, 'code' => $e->errorCode]);

            return self::FAILURE;
        }

        $previous = $reachability->recordSuccess();
        if ($previous !== null && $previous['notified_key'] !== null && $recipients !== []) {
            $this->send($recipients, new GatewayAlertMail(['first_seen_at' => $previous['failing_since']], 'local_recovered'));
        }

        if ($recipients === []) {
            $this->line('No notification recipients configured; '.count($alerts).' open alert(s) not e-mailed.');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($alerts as $alert) {
            $id = is_string($alert['id'] ?? null) ? strtolower($alert['id']) : null;
            if ($id === null) {
                continue;
            }
            if ($this->notifyOnce($id, (string) ($alert['type'] ?? 'unknown'), $alert['severity'] ?? null, $recipients, new GatewayAlertMail($alert))) {
                $sent++;
            }
        }

        $this->info("{$sent} alert e-mail(s) sent.");

        return self::SUCCESS;
    }

    /**
     * @return list<array<mixed>>
     */
    private function openAlerts(GatewayClient $client): array
    {
        $alerts = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $client->list('/alerts', ['status' => 'open'], $cursor, 100);
            array_push($alerts, ...$page->items);
            $cursor = $page->nextCursor;
        } while ($cursor !== null && ++$pages < 5);

        return $alerts;
    }

    /**
     * @param  list<string>  $recipients
     */
    private function handleUnreachable(GatewayReachability $reachability, array $recipients, GatewayException $e): void
    {
        $state = $reachability->recordFailure($e->getMessage());
        Log::warning('Alert notifier: gateway unreachable.', ['since' => $state['failing_since'], 'code' => $e->errorCode]);

        if (! $reachability->isAlerting() || $state['notified_key'] !== null || $recipients === []) {
            return;
        }

        $key = 'local:gateway_unreachable:'.CarbonImmutable::parse($state['failing_since'])->getTimestamp();
        $mail = new GatewayAlertMail(['first_seen_at' => $state['failing_since'], 'message' => $state['last_error']], 'local_unreachable');

        if ($this->notifyOnce($key, 'gateway_unreachable', 'critical', $recipients, $mail)) {
            $reachability->markNotified($key);
        }
    }

    /**
     * Claims the alert key (unique row) and sends; if sending fails the claim
     * is released so the next run retries.
     *
     * @param  list<string>  $recipients
     */
    private function notifyOnce(string $key, string $type, mixed $severity, array $recipients, GatewayAlertMail $mail): bool
    {
        try {
            DB::table('alert_notifications')->insert([
                'alert_key' => $key,
                'alert_type' => mb_substr($type, 0, 64),
                'severity' => is_string($severity) ? mb_substr($severity, 0, 16) : null,
                'recipient_count' => count($recipients),
                'notified_at' => now(),
            ]);
        } catch (QueryException) {
            return false; // already notified (primary key)
        }

        if (! $this->send($recipients, $mail)) {
            DB::table('alert_notifications')->where('alert_key', $key)->delete();

            return false;
        }

        return true;
    }

    /**
     * One message per recipient so addresses are not disclosed to each other.
     *
     * @param  list<string>  $recipients
     */
    private function send(array $recipients, GatewayAlertMail $mail): bool
    {
        try {
            foreach ($recipients as $recipient) {
                Mail::to($recipient)->send(clone $mail);
            }

            return true;
        } catch (Throwable $e) {
            Log::error('Alert notifier: e-mail failed.', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
