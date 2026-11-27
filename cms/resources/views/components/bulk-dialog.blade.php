@props([
    // Value posted as `action` when confirmed.
    'action',
    'title',
    'triggerLabel',
    'confirmLabel' => 'Confirm',
    'triggerClass' => 'btn btn-secondary btn-sm',
    'confirmClass' => 'btn btn-primary',
    'size' => 'md',
])
{{-- One bulk action inside <x-bulk-bar>: a button that opens a dialog, whose confirm button submits the bar's form. --}}
<div class="confirm" x-data>
    <button type="button" class="{{ $triggerClass }}" x-on:click="$refs.dialog.showModal()" x-bind:disabled="count === 0" aria-haspopup="dialog">{{ $triggerLabel }}</button>
    <dialog x-ref="dialog" @class(['dialog', 'dialog-lg' => $size === 'lg']) aria-labelledby="bulk-{{ $action }}-title">
        <div class="dialog-head">
            <h2 id="bulk-{{ $action }}-title" class="dialog-title">{{ $title }} (<span x-text="count"></span>)</h2>
            <button type="button" class="dialog-close" x-on:click="$refs.dialog.close()" aria-label="Close">&times;</button>
        </div>
        {{ $slot }}
        <div class="dialog-actions">
            <button type="button" class="btn btn-secondary" x-on:click="$refs.dialog.close()">Cancel</button>
            <button type="submit" name="action" value="{{ $action }}" class="{{ $confirmClass }}">{{ $confirmLabel }}</button>
        </div>
    </dialog>
</div>
