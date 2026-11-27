@props([
    // Id of the <x-bulk-bar> form.
    'form',
    // Row value (uuid); omit for the "select all on this page" header box.
    'value' => null,
    'label',
])
@if ($value === null)
    {{-- Alpine, not onchange=: the CSP blocks inline event handlers. --}}
    <input type="checkbox" class="bulk-check" data-bulk-all="{{ $form }}" aria-label="{{ $label }}" x-data
           x-on:change="document.querySelectorAll('input[form={{ $form }}][name=\'ids[]\']').forEach(b => b.checked = $el.checked)">
@else
    <input type="checkbox" class="bulk-check" name="ids[]" value="{{ $value }}" form="{{ $form }}" aria-label="{{ $label }}">
@endif
