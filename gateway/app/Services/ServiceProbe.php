<?php

namespace App\Services;

/**
 * Reports systemd unit state via `systemctl is-active` (audit G14).
 * The unit list comes from config only (never from a request), is validated
 * against a strict pattern, and is passed as an argv array, so no shell runs.
 * Anything that cannot be determined is "unknown", never "inactive".
 */
class ServiceProbe
{
    public const STATES = ['active', 'activating', 'deactivating', 'reloading', 'inactive', 'failed', 'unknown'];

    /** @return array<int, array{name: string, status: string}> */
    public function statuses(): array
    {
        $out = [];
        foreach ((array) config('gateway.monitored_services', []) as $unit) {
            $unit = (string) $unit;
            $out[] = ['name' => $unit, 'status' => $this->status($unit)];
        }

        return $out;
    }

    public function status(string $unit): string
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9@._-]{0,63}$/', $unit) || PHP_OS_FAMILY !== 'Linux' || ! function_exists('proc_open')) {
            return 'unknown';
        }
        $proc = @proc_open(['systemctl', 'is-active', '--', $unit], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            return 'unknown';
        }
        $state = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        // "inactive" is also what systemctl prints for a unit that does not exist; it is
        // reported as such so a typo in the config surfaces as an alert rather than silence.
        return in_array($state, self::STATES, true) ? $state : 'unknown';
    }
}
