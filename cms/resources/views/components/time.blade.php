@props(['value', 'format' => 'j M Y, H:i'])
@if ($value)
    <time datetime="{{ \App\Support\DisplayTime::iso($value) }}" title="{{ \App\Support\DisplayTime::format($value, 'j M Y, H:i:s T') }}">{{ \App\Support\DisplayTime::format($value, $format) }}</time>
@else
    <span class="muted">—</span>
@endif
