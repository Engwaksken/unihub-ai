@props([
    // Form id. Row checkboxes (<x-bulk-check>) point at it with the form= attribute, so
    // they work inside a table that also holds per-row forms (forms cannot nest).
    'id',
    'action',
    // Plural noun for the counter, e.g. "projects".
    'noun' => 'items',
])
{{--
    Toolbar for acting on the selected rows. Each action is a submit button (or a dialog
    holding one) with name="action". Fields inside dialogs are part of this form, so they
    must not be `required` (another action's submit would be blocked); the server validates.
--}}
<form id="{{ $id }}" method="POST" action="{{ $action }}" {{ $attributes->merge(['class' => 'bulk-bar']) }}
      x-data="{
          count: 0,
          boxes() { return document.querySelectorAll('input[form={{ $id }}][name=\'ids[]\']'); },
          sync() {
              const boxes = [...this.boxes()];
              this.count = boxes.filter(b => b.checked).length;
              document.querySelectorAll('input[data-bulk-all={{ $id }}]').forEach(a => {
                  a.checked = boxes.length > 0 && this.count === boxes.length;
                  a.indeterminate = this.count > 0 && this.count < boxes.length;
              });
          },
          clear() { this.boxes().forEach(b => b.checked = false); this.sync(); },
      }"
      x-init="sync()"
      x-on:change.document="setTimeout(() => sync())"
      x-on:bulk-reset.window="setTimeout(() => sync())">
    @csrf
    <x-idempotency-key />
    <p class="bulk-count" role="status" aria-live="polite">
        <span x-show="count === 0">Select {{ $noun }} to edit or delete them together.</span>
        <span x-show="count > 0" x-cloak><strong x-text="count"></strong> {{ $noun }} selected</span>
    </p>
    <div class="bulk-actions">
        {{ $slot }}
        <button type="button" class="btn btn-ghost btn-sm" x-show="count > 0" x-cloak x-on:click="clear()">Clear selection</button>
    </div>
</form>
