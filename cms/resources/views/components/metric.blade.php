@props(['label', 'value', 'hint' => null, 'state' => null])
{{-- $state: null (fresh), 'stale' or 'unknown'. Unknown values are never shown as 0. --}}
<div @class(['metric', 'metric-stale' => $state === 'stale', 'metric-unknown' => $state === 'unknown' || $value === \App\Support\Fmt::UNKNOWN])>
    <dt class="metric-label">{{ $label }}</dt>
    <dd class="metric-value">
        {{ $value }}
        @if ($state === 'stale')
            <span class="badge badge-warning">Stale</span>
        @endif
    </dd>
    @if ($hint)
        <dd class="metric-hint">{{ $hint }}</dd>
    @endif
</div>
