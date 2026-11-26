@props([
    'title',
    'action',
    'method' => 'POST',
    'triggerLabel',
    'confirmLabel' => 'Confirm',
    'triggerClass' => 'btn btn-danger btn-sm',
    'confirmClass' => 'btn btn-danger',
])
@php($dialogId = 'dlg-'.\Illuminate\Support\Str::random(8))
<div class="confirm" x-data>
    <button type="button" class="{{ $triggerClass }}" x-on:click="$refs.dialog.showModal()" aria-haspopup="dialog">
        {{ $triggerLabel }}
    </button>
    <dialog x-ref="dialog" class="dialog" aria-labelledby="{{ $dialogId }}-title" aria-describedby="{{ $dialogId }}-body">
        <form method="POST" action="{{ $action }}">
            @csrf
            @if (strtoupper($method) !== 'POST')
                @method($method)
            @endif
            <x-idempotency-key />
            {{ $fields ?? '' }}
            <h2 id="{{ $dialogId }}-title" class="dialog-title">{{ $title }}</h2>
            <div id="{{ $dialogId }}-body" class="dialog-body">{{ $slot }}</div>
            <div class="dialog-actions">
                <button type="button" class="btn btn-secondary" x-on:click="$refs.dialog.close()" autofocus>Cancel</button>
                <button type="submit" class="{{ $confirmClass }}">{{ $confirmLabel }}</button>
            </div>
        </form>
    </dialog>
</div>
