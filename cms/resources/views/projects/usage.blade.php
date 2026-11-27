<x-layouts.app :title="$project->name.' · Usage'">
    <x-project-header :project="$project" />

    <form method="GET" action="{{ route('projects.usage', $project->id) }}" class="filters" aria-label="Usage filters">
        @include('usage._filter_fields', ['groups' => \Illuminate\Support\Arr::only(\App\Http\Controllers\UsageController::GROUPS, ['day', 'week', 'month', 'key', 'model']), 'projectId' => $project->id])
        <div class="filters-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
        </div>
    </form>
    <x-form-errors />

    @include('usage._report', ['exportParams' => array_filter([
        'from' => $filters['from'], 'to' => $filters['to'], 'group_by' => $filters['group_by'], 'project_id' => $project->id,
        'api_key_id' => $filters['api_key_id'] ?? null, 'model' => $filters['model'] ?? null,
    ])])
</x-layouts.app>
