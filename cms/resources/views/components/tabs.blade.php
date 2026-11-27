@props([
    // Unique per page (prefixes the tab and panel ids).
    'name',
    // ['key' => 'Label', ...] in display order.
    'tabs',
    // ['key' => ['field', 'field.*', ...]]: after a failed submit the first tab holding an
    // error is opened, so a validation message is never hidden on another tab.
    'fields' => [],
    // Error bag to look in (default bag unless the form uses a named one).
    'bag' => 'default',
    // Tab to open first (e.g. from ?tab= after a filter form reloads the page); errors win.
    'active' => null,
])
@php
    $keys = array_keys($tabs);
    $errorKeys = $errors->getBag($bag)->keys();
    $withError = [];
    foreach ($fields as $tab => $names) {
        foreach ($names as $field) {
            $prefix = str_ends_with($field, '.*') ? substr($field, 0, -1) : null;
            foreach ($errorKeys as $k) {
                if ($prefix !== null ? str_starts_with($k, $prefix) : $k === $field) {
                    $withError[$tab] = true;
                }
            }
        }
    }
    $initial = collect($keys)->first(fn ($k) => isset($withError[$k]))
        ?? (in_array($active, $keys, true) ? $active : ($keys[0] ?? ''));
@endphp
<div {{ $attributes->except('aria-label')->merge(['class' => 'tabset']) }}
     x-data="{ tab: @js($initial), keys: @js($keys), jumped: false,
               go(i) { const k = this.keys[(i + this.keys.length) % this.keys.length]; this.tab = k; this.$nextTick(() => document.getElementById('{{ $name }}-tab-' + k).focus()); } }"
     {{-- A required field on a hidden tab would block the submit with no visible message:
          open its tab first, then let the browser show its message there. --}}
     x-on:invalid.capture="if (jumped) return; jumped = true; setTimeout(() => jumped = false); const p = $event.target.closest('[role=tabpanel]'); if (p && p.id.startsWith('{{ $name }}-panel-')) { const k = p.id.slice({{ strlen($name.'-panel-') }}); if (tab !== k) { tab = k; $nextTick(() => $event.target.reportValidity()); } }">
    <div class="tabset-list" role="tablist" aria-label="{{ $attributes->get('aria-label', 'Sections') }}">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" id="{{ $name }}-tab-{{ $key }}" aria-controls="{{ $name }}-panel-{{ $key }}"
                    class="tabset-tab" x-bind:class="{ 'is-active': tab === '{{ $key }}' }"
                    x-bind:aria-selected="tab === '{{ $key }}' ? 'true' : 'false'"
                    x-bind:tabindex="tab === '{{ $key }}' ? 0 : -1"
                    x-on:click="tab = '{{ $key }}'"
                    x-on:keydown.arrow-right.prevent="go({{ $loop->index }} + 1)"
                    x-on:keydown.arrow-left.prevent="go({{ $loop->index }} - 1)"
                    x-on:keydown.home.prevent="go(0)"
                    x-on:keydown.end.prevent="go(keys.length - 1)">
                {{ $label }}
                @if (! empty($withError[$key]))<span class="tabset-error" aria-label="has errors">!</span>@endif
            </button>
        @endforeach
    </div>
    {{ $slot }}
</div>
