@props([
    // Unique per page. A form inside posts it back as `_modal`, so after a validation
    // error the page re-opens this modal with the errors and old input in place.
    'name',
    'title',
    'triggerLabel' => null,
    'triggerClass' => 'btn btn-primary',
    'size' => 'md',
    'description' => null,
])
@php($open = old('_modal') === $name)
<div {{ $attributes->merge(['class' => 'confirm']) }} x-data x-init="{{ $open ? '$nextTick(() => $refs.dialog.showModal())' : '' }}">
    @if ($triggerLabel !== null)
        <button type="button" class="{{ $triggerClass }}" x-on:click="$refs.dialog.showModal()" aria-haspopup="dialog">{{ $triggerLabel }}</button>
    @endif
    {{ $trigger ?? '' }}
    <dialog x-ref="dialog" @class(['dialog', 'dialog-lg' => $size === 'lg']) aria-labelledby="{{ $name }}-title">
        <div class="dialog-head">
            <h2 id="{{ $name }}-title" class="dialog-title">{{ $title }}</h2>
            <button type="button" class="dialog-close" x-on:click="$refs.dialog.close()" aria-label="Close">&times;</button>
        </div>
        @if ($description)
            <p class="dialog-body">{{ $description }}</p>
        @endif
        {{ $slot }}
    </dialog>
</div>
