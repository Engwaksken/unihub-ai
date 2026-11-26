<x-layouts.app :title="$project->name.' · New API key'">
    <x-project-header :project="$project" />

    <section class="card reveal" aria-labelledby="reveal-heading">
        <h2 id="reveal-heading">{{ $rotated ? 'Key rotated' : 'Key issued' }}: copy the secret now</h2>
        <div class="alert alert-warning" role="alert">
            <p class="alert-title">This is the only time the secret is shown.</p>
            <p>It is not stored by the portal and cannot be retrieved again. If you leave this page without saving it, revoke the key and issue a new one.</p>
        </div>

        <div class="field" x-data="{ copied: false }">
            <label for="secret">Secret for key <code>{{ $key['prefix'] ?? '' }}</code></label>
            <div class="secret-row">
                <input id="secret" type="text" readonly value="{{ $secret }}" autocomplete="off" spellcheck="false" class="secret" x-ref="secret" x-on:focus="$event.target.select()">
                <button type="button" class="btn btn-secondary" x-on:click="navigator.clipboard.writeText($refs.secret.value).then(() => copied = true)">Copy</button>
            </div>
            <p class="field-help" role="status" x-show="copied" x-cloak>Copied to the clipboard.</p>
        </div>

        <dl class="details">
            <div><dt>Label</dt><dd>{{ $key['label'] ?? '—' }}</dd></div>
            <div><dt>Environment</dt><dd>{{ $key['environment'] ?? '—' }}</dd></div>
            <div><dt>Expires</dt><dd>@if (! empty($key['expires_at'])) <x-time :value="$key['expires_at']" /> @else Never @endif</dd></div>
        </dl>
        @if ($rotated)
            <p class="field-help">The previous key keeps working until the end of the overlap period; check its new expiry on the keys list.</p>
        @endif

        <p><a class="btn btn-primary" href="{{ route('projects.keys', $project->id) }}">I have stored the secret — back to keys</a></p>
    </section>
</x-layouts.app>
