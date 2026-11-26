<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tracks consecutive failures of the scheduled gateway poll (ADR D13: the CMS
 * raises its own "gateway unreachable" alert after 2 minutes).
 */
class GatewayReachability
{
    public const KEY = 'gateway_reachability';

    public const THRESHOLD_SECONDS = 120;

    /**
     * @return array{failing_since: ?string, last_error: ?string, notified_key: ?string}
     */
    public function state(): array
    {
        $row = DB::table('system_state')->where('key', self::KEY)->value('value');
        $value = is_string($row) ? json_decode($row, true) : null;

        return [
            'failing_since' => $value['failing_since'] ?? null,
            'last_error' => $value['last_error'] ?? null,
            'notified_key' => $value['notified_key'] ?? null,
        ];
    }

    /**
     * Record a failed poll; returns the state after recording.
     *
     * @return array{failing_since: ?string, last_error: ?string, notified_key: ?string}
     */
    public function recordFailure(string $error, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $state = $this->state();
        $state['failing_since'] ??= $now->utc()->format('Y-m-d\TH:i:s\Z');
        $state['last_error'] = mb_substr($error, 0, 200);
        $this->save($state);

        return $state;
    }

    public function markNotified(string $key): void
    {
        $state = $this->state();
        $state['notified_key'] = $key;
        $this->save($state);
    }

    /**
     * Record a successful poll. Returns the previous state if a failure streak
     * just ended, otherwise null.
     *
     * @return array{failing_since: ?string, last_error: ?string, notified_key: ?string}|null
     */
    public function recordSuccess(): ?array
    {
        $state = $this->state();
        if ($state['failing_since'] === null) {
            return null;
        }
        DB::table('system_state')->where('key', self::KEY)->delete();

        return $state;
    }

    /**
     * True when the current failure streak has lasted at least THRESHOLD_SECONDS.
     */
    public function isAlerting(?CarbonImmutable $now = null): bool
    {
        $since = $this->state()['failing_since'];
        if ($since === null) {
            return false;
        }
        $now ??= CarbonImmutable::now('UTC');

        return CarbonImmutable::parse($since)->diffInSeconds($now) >= self::THRESHOLD_SECONDS;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function save(array $state): void
    {
        DB::table('system_state')->updateOrInsert(['key' => self::KEY], ['value' => json_encode($state), 'updated_at' => now()]);
    }
}
