@php
    use App\Support\Fmt;
    $s = $summary ?? [];
    $stale = (bool) ($s['telemetry_stale'] ?? true);
    $sampled = $s['telemetry_sampled_at'] ?? null;
    $trend = is_array($s['trend'] ?? null) ? $s['trend'] : [];
    $modelStatus = $s['model_status'] ?? 'unknown';
    $tableId = 'trend-table';
@endphp
<x-layouts.app title="Overview">
    <div class="page-header">
        <div>
            <h1>Overview</h1>
            <p class="muted">
                @if ($projectId && isset($projects[$projectId]))
                    {{ $projects[$projectId]->name }}
                @elseif (\App\Authorization\Permissions::projectScope(auth()->user(), 'overview.view') === null)
                    All projects
                @else
                    Your assigned projects
                @endif
            </p>
        </div>
    </div>

    <form method="GET" action="{{ route('overview') }}" class="filters" aria-label="Overview filters">
        <div class="field">
            <label for="from">From</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] }}">
        </div>
        <div class="field">
            <label for="to">To</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] }}">
        </div>
        @if ($projects !== [])
            <div class="field">
                <label for="project_id">Project</label>
                <select id="project_id" name="project_id">
                    <option value="">All visible projects</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}" @selected($projectId === $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="filters-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
        </div>
    </form>
    <x-form-errors />

    @if ($error instanceof \App\Gateway\Exceptions\GatewayValidationException)
        <div class="alert alert-error" role="alert">
            @foreach ($error->fieldErrors() as $messages)
                @foreach ($messages as $m) <p>{{ $m }}</p> @endforeach
            @endforeach
        </div>
    @elseif ($error)
        <x-gateway-unavailable :exception="$error" />
    @else
        <p class="muted small">
            Last updated <x-time :value="$s['generated_at'] ?? null" format="j M Y, H:i:s" />
            · Server telemetry
            @if ($sampled === null)
                <span class="badge badge-warning">Unknown</span> (no sample received yet)
            @elseif ($stale)
                <span class="badge badge-warning">Stale</span> — last sample <x-time :value="$sampled" format="j M Y, H:i:s" />
            @else
                sampled <x-time :value="$sampled" format="H:i:s" />
            @endif
            · Figures cached for up to 30 seconds.
        </p>

        <dl class="metrics">
            <x-metric label="Active projects" :value="Fmt::int($s['active_projects'] ?? null)" />
            <x-metric label="Requests today" :value="Fmt::int($s['requests_today'] ?? null)" hint="Since 00:00 UTC (03:00 EAT)" />
            <x-metric label="Completion rate today" :value="Fmt::ratio($s['completion_rate'] ?? null)"
                      :hint="($s['completion_rate'] ?? null) === null ? 'No finished jobs yet today' : 'Completed ÷ finished'" />
            <x-metric label="Queue depth" :value="Fmt::int($s['queue_depth'] ?? null)" :hint="isset($s['running']) ? Fmt::int($s['running']).' running' : null" />
            <x-metric label="p95 latency (range)" :value="Fmt::ms($s['p95_total_ms'] ?? null)" hint="Queue + inference, selected range" />
            <x-metric label="RAM used" :value="$stale ? ($sampled ? 'Stale' : Fmt::UNKNOWN) : Fmt::pct($s['ram_used_pct'] ?? null)"
                      :state="$sampled === null ? 'unknown' : ($stale ? 'stale' : null)" />
            <x-metric label="Model status" :value="ucfirst((string) $modelStatus)"
                      :hint="$s['loaded_model'] ?? null" :state="$modelStatus === 'unknown' ? 'unknown' : null" />
            <x-metric label="Admission" :value="match ($s['admission'] ?? null) { 'open' => 'Open', 'paused' => 'Paused', default => Fmt::UNKNOWN }"
                      :hint="($s['admission'] ?? null) === 'paused' ? 'New jobs are refused' : null" />
        </dl>

        <section class="card" aria-labelledby="trend-heading">
            <h2 id="trend-heading">Daily trend</h2>
            @if ($trend === [])
                <x-empty-state title="No requests in this range" icon="chart"><p>Nothing was processed between these dates.</p></x-empty-state>
            @else
                <x-chart :table-id="$tableId" label="Requests and failures per day (UTC days)" :spec="[
                    'type' => 'line',
                    'labels' => array_map(fn ($r) => (string) ($r['day'] ?? ''), $trend),
                    'datasets' => [
                        ['label' => 'Requests', 'data' => array_map(fn ($r) => $r['requests'] ?? null, $trend)],
                        ['label' => 'Failed', 'data' => array_map(fn ($r) => $r['failed'] ?? null, $trend)],
                    ],
                ]" />
                <div class="table-wrap">
                    <table class="table table-compact" id="{{ $tableId }}">
                        <caption class="visually-hidden">Requests per day</caption>
                        <thead>
                            <tr><th scope="col">Day (UTC)</th><th scope="col" class="num">Requests</th><th scope="col" class="num">Failed</th><th scope="col" class="num">p95 total</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($trend as $row)
                                <tr>
                                    <th scope="row">{{ $row['day'] ?? '' }}</th>
                                    <td class="num">{{ Fmt::int($row['requests'] ?? null) }}</td>
                                    <td class="num">{{ Fmt::int($row['failed'] ?? null) }}</td>
                                    <td class="num">{{ Fmt::ms($row['p95_total_ms'] ?? null) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</x-layouts.app>
