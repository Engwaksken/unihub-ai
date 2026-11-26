<x-layouts.app :title="$project->name.' · Usage'">
    <x-project-header :project="$project" />

    <form method="GET" action="{{ route('projects.usage', $project->id) }}" class="filters" aria-label="Usage filters">
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
                @foreach (['day' => 'Day', 'model' => 'Model'] as $value => $text)
                    <option value="{{ $value }}" @selected($filters['group_by'] === $value)>{{ $text }}</option>
                @endforeach
            </select>
        </div>
        <div class="filters-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
        </div>
    </form>
    <x-form-errors />

    @include('usage._report', ['exportParams' => [
        'from' => $filters['from'], 'to' => $filters['to'], 'group_by' => $filters['group_by'], 'project_id' => $project->id,
    ]])
</x-layouts.app>
