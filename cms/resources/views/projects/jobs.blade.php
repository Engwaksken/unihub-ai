<x-layouts.app :title="$project->name.' · Jobs'">
    <x-project-header :project="$project" />

    @include('jobs._filters', ['action' => route('projects.jobs', $project->id)])
    <x-form-errors />

    @if ($error)
        <x-gateway-unavailable :exception="$error" />
    @else
        @include('jobs._table', [
            'showProject' => false,
            'paginationRoute' => 'projects.jobs',
            'paginationParams' => ['project' => $project->id] + array_filter(\Illuminate\Support\Arr::only($filters, ['state', 'from', 'to', 'cursor'])),
        ])
    @endif
</x-layouts.app>
