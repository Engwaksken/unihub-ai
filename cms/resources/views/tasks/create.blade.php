<x-layouts.app title="New task version">
    <div class="page-header">
        <div>
            <p class="breadcrumb"><a href="{{ route('tasks.index') }}">Models &amp; tasks</a></p>
            <h1>New task or draft version</h1>
            <p class="muted">Using an existing key adds a new draft version to that task. Drafts do not affect live traffic until published.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('tasks.store') }}" class="card form" x-data="{ busy: false }" x-on:submit="busy = true">
        @csrf
        <x-idempotency-key />
        <x-form-errors />

        <div class="form-grid">
            <div class="field">
                <label for="key">Task key</label>
                <input id="key" name="key" type="text" maxlength="64" required pattern="[a-z][a-z0-9_]{1,63}" value="{{ old('key', $key) }}" aria-describedby="key-help">
                <p id="key-help" class="field-help">Lowercase letters, digits and underscores, e.g. <code>daily_plan</code>.</p>
                @error('key') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="model_id">Model</label>
                <select id="model_id" name="model_id" required>
                    <option value="">Choose…</option>
                    @foreach ($models as $m)
                        <option value="{{ $m['id'] ?? '' }}" @selected(old('model_id') === ($m['id'] ?? null))>{{ $m['tag'] ?? '' }}{{ ! empty($m['enabled']) ? ' (enabled)' : ' (disabled — enable before publishing)' }}</option>
                    @endforeach
                </select>
                @error('model_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="output_cap">Output cap (tokens, max 256)</label>
                <input id="output_cap" name="output_cap" type="number" min="1" max="256" required value="{{ old('output_cap', 256) }}">
                @error('output_cap') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="output_format">Output format</label>
                <select id="output_format" name="output_format">
                    <option value="text" @selected(old('output_format', 'text') === 'text')>Text</option>
                    <option value="json" @selected(old('output_format') === 'json')>JSON</option>
                </select>
                @error('output_format') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="field">
            <label for="description">Description <span class="optional">(optional)</span></label>
            <input id="description" name="description" type="text" maxlength="500" value="{{ old('description') }}">
            @error('description') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="system_template">System template</label>
            <textarea id="system_template" name="system_template" rows="10" minlength="10" maxlength="4000" required class="mono" aria-describedby="template-help">{{ old('system_template') }}</textarea>
            <p id="template-help" class="field-help">A fixed system prompt (10–4000 characters). User input is sent separately as the user message and is never interpolated into this template.</p>
            @error('system_template') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="form-actions">
            <a href="{{ route('tasks.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary" x-bind:disabled="busy">Create draft version</button>
        </div>
    </form>
</x-layouts.app>
