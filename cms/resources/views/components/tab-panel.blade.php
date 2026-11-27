@props(['tabs', 'key'])
{{-- One panel inside <x-tabs name="..."> ; $tabs is that component's name. Without JavaScript every panel is visible. --}}
<div role="tabpanel" id="{{ $tabs }}-panel-{{ $key }}" aria-labelledby="{{ $tabs }}-tab-{{ $key }}" tabindex="0"
     {{ $attributes->merge(['class' => 'tabset-panel']) }} x-show="tab === '{{ $key }}'">
    {{ $slot }}
</div>
