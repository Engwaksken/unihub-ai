{{-- Expects $rows (list|null), $meta, $filters, $error, $exportParams (array). --}}
@php
    use App\Support\Fmt;
    $groupLabel = ['day' => 'Day', 'project' => 'Project', 'model' => 'Model'][$filters['group_by']] ?? 'Group';
    $tableId = 'usage-table';
@endphp
@if ($error)
    <x-gateway-unavailable :exception="$error" />
@elseif ($rows === [])
    <x-empty-state title="No usage in this range" icon="chart"><p>No jobs finished between these dates.</p></x-empty-state>
@else
    @php
        $label = fn ($r) => $filters['group_by'] === 'project' ? (string) ($r['project_name'] ?? $r['key'] ?? '') : (string) ($r['key'] ?? '');
        $totals = ['requests' => 0, 'failed' => 0, 'cost' => null];
        foreach ($rows as $r) {
            $totals['requests'] += (int) ($r['requests'] ?? 0);
            $totals['failed'] += (int) ($r['failed'] ?? 0);
            if (is_numeric($r['est_cost_usd'] ?? null)) {
                $totals['cost'] = ($totals['cost'] ?? 0) + (float) $r['est_cost_usd'];
            }
        }
    @endphp

    <dl class="metrics">
        <x-metric label="Requests" :value="Fmt::int($totals['requests'])" />
        <x-metric label="Failure rate" :value="$totals['requests'] > 0 ? Fmt::ratio($totals['failed'] / $totals['requests']) : Fmt::UNKNOWN" />
        <x-metric label="Estimated cost" :value="Fmt::usd($totals['cost'])" hint="Estimate" />
    </dl>

    <div class="actions-row">
        <a class="btn btn-secondary" href="{{ route('usage.export', $exportParams) }}">Export CSV</a>
        <span class="muted small">CSV comes straight from the gateway with the same filters and scope.</span>
    </div>

    <x-chart :table-id="$tableId" :label="'Requests and failures by '.strtolower($groupLabel)" :spec="[
        'type' => $filters['group_by'] === 'day' ? 'line' : 'bar',
        'labels' => array_map($label, $rows),
        'datasets' => [
            ['label' => 'Completed', 'data' => array_map(fn ($r) => $r['completed'] ?? null, $rows)],
            ['label' => 'Failed', 'data' => array_map(fn ($r) => $r['failed'] ?? null, $rows)],
        ],
    ]" />

    <div class="table-wrap">
        <table class="table table-compact" id="{{ $tableId }}">
            <caption class="visually-hidden">Usage by {{ strtolower($groupLabel) }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ $groupLabel }}</th>
                    <th scope="col" class="num">Requests</th>
                    <th scope="col" class="num">Failed</th>
                    <th scope="col" class="num">Failure rate</th>
                    <th scope="col" class="num">Tokens in</th>
                    <th scope="col" class="num">Tokens out</th>
                    <th scope="col" class="num">p50 / p95 queue</th>
                    <th scope="col" class="num">p50 / p95 inference</th>
                    <th scope="col" class="num">p95 total</th>
                    <th scope="col" class="num">Cost (estimate)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <th scope="row">{{ $label($r) }}
                            @if ($filters['group_by'] === 'project' && ! empty($r['project_slug'])) <span class="cell-sub">{{ $r['project_slug'] }}</span> @endif</th>
                        <td class="num">{{ Fmt::int($r['requests'] ?? null) }}</td>
                        <td class="num">{{ Fmt::int($r['failed'] ?? null) }}</td>
                        <td class="num">{{ Fmt::ratio($r['failure_rate'] ?? null) }}</td>
                        <td class="num">{{ Fmt::tokens($r['tokens_in'] ?? null) }}</td>
                        <td class="num">{{ Fmt::tokens($r['tokens_out'] ?? null) }}</td>
                        <td class="num">{{ Fmt::ms($r['p50_queue_ms'] ?? null) }} / {{ Fmt::ms($r['p95_queue_ms'] ?? null) }}</td>
                        <td class="num">{{ Fmt::ms($r['p50_inference_ms'] ?? null) }} / {{ Fmt::ms($r['p95_inference_ms'] ?? null) }}</td>
                        <td class="num">{{ Fmt::ms($r['p95_total_ms'] ?? null) }}</td>
                        <td class="num">{{ Fmt::usd($r['est_cost_usd'] ?? null) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="muted small">
        Cost is an <strong>estimate</strong>: {{ $meta['cost_note'] ?? 'hosting cost allocated by share of inference time.' }}
        Tokens show “n/a” when the model did not report counts. Days follow the {{ \App\Support\DisplayTime::zone() }} calendar.
    </p>
@endif
