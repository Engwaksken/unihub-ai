@php
    use App\Support\DisplayTime;
    use App\Support\Fmt;
    $c = is_array($health['current'] ?? null) ? $health['current'] : [];
    $sampled = $c['sampled_at'] ?? null;
    $state = $sampled === null ? 'unknown' : (! empty($c['stale']) ? 'stale' : null);
    // A stale sample is not shown as current: the value reads "Stale" and the last known value is a hint.
    $metric = function (string $formatted) use ($state, $sampled) {
        return match ($state) {
            'unknown' => [Fmt::UNKNOWN, null],
            'stale' => ['Stale', 'Last known '.$formatted.' at '.DisplayTime::format($sampled, 'j M, H:i')],
            default => [$formatted, null],
        };
    };
    $hb = DisplayTime::parse($c['worker_heartbeat_at'] ?? null);
    $hbAge = $hb ? (int) $hb->diffInSeconds(now()) : null;
    $tls = DisplayTime::parse($c['tls_expires_at'] ?? null);
    $tlsDays = $tls ? (int) floor(now()->diffInDays($tls, false)) : null;
    $history = is_array($health['history'] ?? null) ? $health['history'] : [];
    $workers = is_array($health['workers'] ?? null) ? $health['workers'] : [];
    $services = is_array($c['services'] ?? null) ? $c['services'] : [];
    $hasSwap = is_numeric($c['swap_total_mb'] ?? null) && $c['swap_total_mb'] > 0;
@endphp
<x-layouts.app title="Server & alerts">
    <div class="page-header">
        <div>
            <h1>Server &amp; alerts</h1>
            <p class="muted">Telemetry from the gateway host, sampled every 30 seconds. Missing values show as Unknown and old samples as Stale, never as zero.</p>
        </div>
    </div>
    <x-form-errors />

    @if ($localAlerting)
        <div class="alert alert-error" role="alert">
            <p class="alert-title">CMS alert: gateway unreachable</p>
            <p>The scheduled check has not reached the gateway since <x-time :value="$local['failing_since']" format="j M Y, H:i" />. Last error: {{ $local['last_error'] ?? 'unknown' }}</p>
        </div>
    @endif

    @if ($error)
        <x-gateway-unavailable :exception="$error" />
    @else
        <section class="card" aria-labelledby="current-heading">
            <div class="section-head">
                <h2 id="current-heading">Current state</h2>
                <p class="muted small">
                    @if ($state === 'unknown')
                        <span class="badge badge-warning">Unknown</span> No telemetry sample has been received.
                    @elseif ($state === 'stale')
                        <span class="badge badge-warning">Stale</span> Last sample <x-time :value="$sampled" format="j M Y, H:i:s" />
                    @else
                        Sampled <x-time :value="$sampled" format="j M Y, H:i:s" />
                    @endif
                    @if (! empty($c['host_id'])) · host {{ $c['host_id'] }} @endif
                </p>
            </div>
            <dl class="metrics">
                @php([$v, $h] = $metric(Fmt::pct($c['cpu_pct'] ?? null)))
                <x-metric label="CPU" :value="$v" :hint="$h" :state="$state" />
                @php([$v, $h] = $metric(Fmt::pct($c['ram_used_pct'] ?? null)))
                <x-metric label="RAM used" :value="$v" :state="$state"
                          :hint="$h ?? (is_numeric($c['ram_used_mb'] ?? null) && is_numeric($c['ram_total_mb'] ?? null) ? Fmt::int($c['ram_used_mb']).' of '.Fmt::int($c['ram_total_mb']).' MB' : null)" />
                @php([$v, $h] = $metric(! array_key_exists('swap_total_mb', $c) || $c['swap_total_mb'] === null ? Fmt::UNKNOWN : ($hasSwap ? Fmt::pct($c['swap_used_pct'] ?? null) : 'No swap')))
                <x-metric label="Swap used" :value="$v" :state="$state"
                          :hint="$h ?? ($hasSwap && is_numeric($c['swap_used_mb'] ?? null) ? Fmt::int($c['swap_used_mb']).' of '.Fmt::int($c['swap_total_mb']).' MB' : null)" />
                @php([$v, $h] = $metric(Fmt::pct($c['disk_used_pct'] ?? null)))
                <x-metric label="Disk used" :value="$v" :hint="$h" :state="$state" />
                @php([$v, $h] = $metric(Fmt::int($c['queue_depth'] ?? null)))
                <x-metric label="Queue depth" :value="$v" :state="$state" :hint="$h ?? (isset($c['running']) ? Fmt::int($c['running']).' running' : null)" />
                <x-metric label="Worker heartbeat"
                          :value="$hbAge === null ? Fmt::UNKNOWN : ($hbAge > 60 ? 'Missing' : 'OK')"
                          :hint="$hb ? 'Last beat '.DisplayTime::format($hb, 'j M, H:i:s').' ('.$hbAge.' s ago)' : 'No heartbeat recorded'"
                          :state="$hbAge === null ? 'unknown' : null" />
                <x-metric label="Ollama" :value="$state === 'unknown' ? Fmt::UNKNOWN : ucfirst((string) ($c['ollama_status'] ?? 'unknown'))"
                          :hint="$c['loaded_model'] ?? null" :state="$state === 'stale' ? 'stale' : (($c['ollama_status'] ?? 'unknown') === 'unknown' ? 'unknown' : null)" />
                <x-metric label="TLS certificate"
                          :value="$tlsDays === null ? Fmt::UNKNOWN : ($tlsDays < 0 ? 'Expired' : $tlsDays.' days left')"
                          :hint="$tls ? 'Expires '.DisplayTime::format($tls, 'j M Y') : null"
                          :state="$tlsDays === null ? 'unknown' : null" />
                <x-metric label="Admission" :value="match ($admission) { 'open' => 'Open', 'paused' => 'Paused', default => Fmt::UNKNOWN }"
                          :hint="$admission === 'paused' ? 'New jobs are refused' : null" />
            </dl>

            @can('admission.manage')
                @if ($admission !== null)
                    @php($target = $admission === 'paused' ? 'open' : 'paused')
                    <div x-data class="confirm">
                        <button type="button" class="btn {{ $target === 'paused' ? 'btn-danger' : 'btn-primary' }}" x-on:click="$refs.admission.showModal()" aria-haspopup="dialog">
                            {{ $target === 'paused' ? 'Pause admission…' : 'Resume admission…' }}
                        </button>
                        <dialog x-ref="admission" class="dialog" aria-labelledby="admission-title">
                            <form method="POST" action="{{ route('admission.update') }}">
                                @csrf
                                @method('PUT')
                                <x-idempotency-key />
                                <input type="hidden" name="state" value="{{ $target }}">
                                <h2 id="admission-title" class="dialog-title">{{ $target === 'paused' ? 'Pause admission of new jobs?' : 'Resume admission of new jobs?' }}</h2>
                                <p class="dialog-body">
                                    @if ($target === 'paused')
                                        Every project's new job submissions are refused until admission is resumed. Queued and running jobs continue.
                                    @else
                                        Projects can submit new jobs again.
                                    @endif
                                </p>
                                <div class="field">
                                    <label for="admission-reason">Reason (required, recorded in the audit log)</label>
                                    <textarea id="admission-reason" name="reason" rows="2" minlength="3" maxlength="200" required>{{ old('reason') }}</textarea>
                                </div>
                                <div class="dialog-actions">
                                    <button type="button" class="btn btn-secondary" x-on:click="$refs.admission.close()">Cancel</button>
                                    <button type="submit" class="btn {{ $target === 'paused' ? 'btn-danger' : 'btn-primary' }}">{{ $target === 'paused' ? 'Pause admission' : 'Resume admission' }}</button>
                                </div>
                            </form>
                        </dialog>
                    </div>
                @endif
            @endcan
        </section>

        <section class="card" aria-labelledby="services-heading">
            <h2 id="services-heading">Services</h2>
            @if ($services === [])
                <p class="muted">No service status reported. Set <code>GATEWAY_MONITORED_SERVICES</code> on the gateway to list systemd units.</p>
            @else
                <div class="table-wrap">
                    <table class="table table-compact">
                        <caption class="visually-hidden">systemd services on the gateway host</caption>
                        <thead><tr><th scope="col">Service</th><th scope="col">Status</th></tr></thead>
                        <tbody>
                            @foreach ($services as $svc)
                                @php($st = (string) ($svc['status'] ?? 'unknown'))
                                <tr>
                                    <th scope="row"><code>{{ $svc['name'] ?? '' }}</code></th>
                                    <td>
                                        @if ($state === 'stale')
                                            <span class="badge badge-warning">Stale</span> <span class="muted small">last known {{ $st }}</span>
                                        @else
                                            <span @class(['badge', 'badge-success' => $st === 'active', 'badge-error' => in_array($st, ['inactive', 'failed'], true), 'badge-warning' => ! in_array($st, ['active', 'inactive', 'failed'], true)])>{{ $st === 'unknown' ? Fmt::UNKNOWN : ucfirst($st) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="history-heading">
            <div class="section-head">
                <h2 id="history-heading">History</h2>
                <form method="GET" action="{{ route('health.index') }}" class="inline-form">
                    <input type="hidden" name="status" value="{{ $filters['status'] }}">
                    <label for="hours" class="small">Window</label>
                    <select id="hours" name="hours">
                        @foreach ([6 => '6 hours', 24 => '24 hours', 72 => '3 days', 168 => '7 days'] as $h => $text)
                            <option value="{{ $h }}" @selected($filters['hours'] === $h)>{{ $text }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-secondary btn-sm">Show</button>
                </form>
            </div>
            @if ($history === [])
                <p class="muted">No samples in this window.</p>
            @else
                <x-chart table-id="history-table" label="CPU, RAM, disk and swap use (%) over time" :spec="[
                    'type' => 'line',
                    'yLabel' => '%',
                    'labels' => array_map(fn ($s) => DisplayTime::format($s['sampled_at'] ?? null, 'j M H:i'), $history),
                    'datasets' => [
                        ['label' => 'CPU %', 'data' => array_map(fn ($s) => $s['cpu_pct'] ?? null, $history)],
                        ['label' => 'RAM %', 'data' => array_map(fn ($s) => $s['ram_used_pct'] ?? null, $history)],
                        ['label' => 'Disk %', 'data' => array_map(fn ($s) => $s['disk_used_pct'] ?? null, $history)],
                        ['label' => 'Swap %', 'data' => array_map(fn ($s) => $s['swap_used_pct'] ?? null, $history)],
                    ],
                ]" />
                <details>
                    <summary>Show samples as a table ({{ count($history) }})</summary>
                    <div class="table-wrap">
                        <table class="table table-compact" id="history-table">
                            <caption class="visually-hidden">Telemetry samples</caption>
                            <thead><tr><th scope="col">Sampled</th><th scope="col" class="num">CPU</th><th scope="col" class="num">RAM</th><th scope="col" class="num">Disk</th><th scope="col" class="num">Swap</th><th scope="col" class="num">Queue</th></tr></thead>
                            <tbody>
                                @foreach (array_reverse($history) as $s)
                                    <tr>
                                        <th scope="row"><x-time :value="$s['sampled_at'] ?? null" format="j M, H:i:s" /></th>
                                        <td class="num">{{ Fmt::pct($s['cpu_pct'] ?? null) }}</td>
                                        <td class="num">{{ Fmt::pct($s['ram_used_pct'] ?? null) }}</td>
                                        <td class="num">{{ Fmt::pct($s['disk_used_pct'] ?? null) }}</td>
                                        <td class="num">{{ Fmt::pct($s['swap_used_pct'] ?? null) }}</td>
                                        <td class="num">{{ Fmt::int($s['queue_depth'] ?? null) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif
        </section>

        <section class="card" aria-labelledby="workers-heading">
            <h2 id="workers-heading">Workers</h2>
            @if ($workers === [])
                <p class="muted">No worker has reported a heartbeat.</p>
            @else
                <div class="table-wrap">
                    <table class="table table-compact">
                        <caption class="visually-hidden">Workers</caption>
                        <thead><tr><th scope="col">Worker</th><th scope="col">PID</th><th scope="col">Busy</th><th scope="col">Started</th><th scope="col">Last heartbeat</th></tr></thead>
                        <tbody>
                            @foreach ($workers as $w)
                                @php($beat = DisplayTime::parse($w['heartbeat_at'] ?? null))
                                <tr>
                                    <th scope="row"><code>{{ $w['worker_id'] ?? '' }}</code></th>
                                    <td>{{ $w['pid'] ?? '—' }}</td>
                                    <td>{{ ! empty($w['busy']) ? 'Running a job' : 'Idle' }}</td>
                                    <td><x-time :value="$w['started_at'] ?? null" /></td>
                                    <td><x-time :value="$w['heartbeat_at'] ?? null" format="j M, H:i:s" />
                                        @if ($beat && $beat->diffInSeconds(now()) > 60) <span class="badge badge-warning">Missing</span> @endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="alerts-heading">
            <div class="section-head">
                <h2 id="alerts-heading">Alerts</h2>
                <form method="GET" action="{{ route('health.index') }}" class="inline-form">
                    <input type="hidden" name="hours" value="{{ $filters['hours'] }}">
                    <label for="alert-status" class="small">Status</label>
                    <select id="alert-status" name="status">
                        @foreach (['active' => 'Open & acknowledged', 'open' => 'Open', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved'] as $value => $text)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $text }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-secondary btn-sm">Show</button>
                </form>
            </div>
            @if ($alerts->isEmpty())
                <p class="muted">No alerts with this status.</p>
            @else
                <div class="table-wrap">
                    <table class="table table-compact">
                        <caption class="visually-hidden">Alerts</caption>
                        <thead><tr><th scope="col">Alert</th><th scope="col">Severity</th><th scope="col">Status</th><th scope="col">First / last seen</th><th scope="col">Acknowledged</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                        <tbody>
                            @foreach ($alerts->items as $a)
                                <tr>
                                    <th scope="row">{{ $a['type'] ?? 'alert' }}<span class="cell-sub">{{ $a['message'] ?? '' }}</span></th>
                                    <td><x-status-badge :status="(string) ($a['severity'] ?? 'unknown')" /></td>
                                    <td><x-status-badge :status="(string) ($a['status'] ?? 'unknown')" /></td>
                                    <td><x-time :value="$a['first_seen_at'] ?? null" /><span class="cell-sub">last <x-time :value="$a['last_seen_at'] ?? null" /></span></td>
                                    <td>@if (! empty($a['acked_at'])) <x-time :value="$a['acked_at']" /><span class="cell-sub">{{ $a['acked_by'] ?? '' }}</span> @else <span class="muted">—</span> @endif</td>
                                    <td class="row-actions">
                                        @if (($a['status'] ?? '') === 'open')
                                            @can('alert.acknowledge')
                                                <form method="POST" action="{{ route('alerts.ack', $a['id'] ?? '') }}">
                                                    @csrf
                                                    <x-idempotency-key />
                                                    <button type="submit" class="btn btn-secondary btn-sm">Acknowledge<span class="visually-hidden"> {{ $a['type'] ?? '' }}</span></button>
                                                </form>
                                            @endcan
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($alerts->nextCursor)
                    <nav class="pager" aria-label="Pagination"><a class="btn btn-secondary btn-sm" href="{{ route('health.index', ['status' => $filters['status'], 'hours' => $filters['hours'], 'cursor' => $alerts->nextCursor]) }}">Older alerts</a></nav>
                @endif
            @endif
        </section>
    @endif
</x-layouts.app>
