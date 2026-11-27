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

        @include('tasks._fields')

        <div class="form-actions">
            <a href="{{ route('tasks.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary" x-bind:disabled="busy">Create draft version</button>
        </div>
    </form>
</x-layouts.app>
