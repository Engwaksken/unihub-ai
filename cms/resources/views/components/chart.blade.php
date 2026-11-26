@props(['spec', 'label', 'tableId' => null])
{{-- Chart.js canvas (bundled by Vite). The numbers are always also available in a data table ($tableId). --}}
<figure class="chart">
    <div class="chart-box">
        <canvas role="img" aria-label="{{ $label }}" data-chart="{{ json_encode($spec, JSON_UNESCAPED_SLASHES) }}" @if ($tableId) aria-describedby="{{ $tableId }}" @endif>
            {{ $label }}. The same data is listed in the table.
        </canvas>
    </div>
    <figcaption class="muted small">{{ $label }}@if ($tableId) — <a href="#{{ $tableId }}">data table</a>@endif</figcaption>
</figure>
