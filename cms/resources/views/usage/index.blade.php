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
        <div class="field">
            <label for="from">From</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] }}">
        </div>
        <div class="field">
            <label for="to">To</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] }}">
        </div>
        <div class="field">
            <label for="group_by">Group by</label>
            <select id="group_by" name="group_by">
                @foreach (['day' => 'Day', 'project' => 'Project', 'model' => 'Model'] as $value => $text)
                    <option value="{{ $value }}" @selected($filters['group_by'] === $value)>{{ $text }}</option>
                @endforeach
            </select>
        </div>
        @if ($projects !== [])
            <div class="field">
                <label for="project_id">Project</label>
                <select id="project_id" name="project_id">
                    <option value="">All visible projects</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}" @selected($projectId === $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="filters-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
        </div>
    </form>
    <x-form-errors />

    @include('usage._report', ['exportParams' => array_filter([
        'from' => $filters['from'], 'to' => $filters['to'], 'group_by' => $filters['group_by'], 'project_id' => $projectId,
    ])])
</x-layouts.app>
