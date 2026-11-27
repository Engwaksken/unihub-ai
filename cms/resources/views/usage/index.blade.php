<x-layouts.app title="Usage & reports">
    <div class="page-header">
        <div>
            <h1>Usage &amp; reports</h1>
            <p class="muted">
                @if ($projectId && isset($projects[$projectId])) {{ $projects[$projectId]->name }}
                @elseif (\App\Authorization\Permissions::projectScope(auth()->user(), 'usage.view') === null) All projects
                @else Your assigned projects @endif
            </p>
        </div>
    </div>

    <form method="GET" action="{{ route('usage.index') }}" class="filters" aria-label="Usage filters">
        @include('usage._filter_fields', ['groups' => \App\Http\Controllers\UsageController::GROUPS])
        <div class="filters-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
        </div>
    </form>
    <x-form-errors />

    @include('usage._report', ['exportParams' => array_filter([
        'from' => $filters['from'], 'to' => $filters['to'], 'group_by' => $filters['group_by'], 'project_id' => $projectId,
        'organization_id' => $filters['organization_id'] ?? null, 'api_key_id' => $filters['api_key_id'] ?? null, 'model' => $filters['model'] ?? null,
    ])])
</x-layouts.app>
