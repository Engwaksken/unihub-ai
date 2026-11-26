{{-- Expects $action (url), $filters, optionally $projects + $projectId for the project selector. --}}
<form method="GET" action="{{ $action }}" class="filters" aria-label="Filter jobs">
    @isset($projects)
        @if (count($projects) > 0)
            <div class="field">
                <label for="job-project">Project</label>
                <select id="job-project" name="project_id">
                    <option value="">All visible projects</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}" @selected(($projectId ?? null) === $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    @endisset
    <div class="field">
        <label for="job-state">State</label>
        <select id="job-state" name="state">
            <option value="">Any</option>
            @foreach (\App\Http\Controllers\JobController::STATES as $state)
                <option value="{{ $state }}" @selected(($filters['state'] ?? '') === $state)>{{ ucfirst($state) }}</option>
            @endforeach
        </select>
    </div>
    <div class="field">
        <label for="job-from">From</label>
        <input id="job-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}">
    </div>
    <div class="field">
        <label for="job-to">To</label>
        <input id="job-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}">
    </div>
    <div class="filters-actions">
        <button type="submit" class="btn btn-secondary">Apply</button>
        <a href="{{ $action }}" class="btn btn-ghost">Clear</a>
    </div>
</form>
