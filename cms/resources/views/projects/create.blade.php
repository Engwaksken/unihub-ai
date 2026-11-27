<x-layouts.app title="New project">
    <div class="page-header">
        <div>
            <p class="breadcrumb"><a href="{{ route('projects.index') }}">Projects</a></p>
            <h1>New project</h1>
        </div>
    </div>

    @if ($organizations === [])
        <x-empty-state title="No active organization" icon="building">
            <p>A project belongs to an organization. Create or restore an organization first.</p>
            @can('organization.view')
                <p><a class="btn btn-secondary" href="{{ route('organizations.index') }}">Go to organizations</a></p>
            @endcan
        </x-empty-state>
    @else
        <form method="POST" action="{{ route('projects.store') }}" class="card form" x-data="{ busy: false }" x-on:submit="busy = true">
            @csrf
            <x-idempotency-key />
            <x-form-errors />

            @include('projects._create_fields')

            <div class="form-actions">
                <a href="{{ route('projects.index') }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary" x-bind:disabled="busy">Create project</button>
            </div>
        </form>
    @endif
</x-layouts.app>
