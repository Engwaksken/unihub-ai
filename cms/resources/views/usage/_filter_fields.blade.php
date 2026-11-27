{{-- Expects $filters, $groups (value => label), $keys (uuid => label), $models (list), optional $organizations, $projects, $projectId. --}}
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
        @foreach ($groups as $value => $text)
            <option value="{{ $value }}" @selected($filters['group_by'] === $value)>{{ $text }}</option>
        @endforeach
    </select>
</div>
@if (! empty($organizations) && count($organizations) > 1)
    <div class="field">
        <label for="organization_id">Organization</label>
        <select id="organization_id" name="organization_id">
            <option value="">All organizations</option>
            @foreach ($organizations as $id => $name)
                <option value="{{ $id }}" @selected(($filters['organization_id'] ?? null) === $id)>{{ $name }}</option>
            @endforeach
        </select>
    </div>
@endif
@if (! empty($projects))
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
@if ($keys !== [])
    <div class="field">
        <label for="api_key_id">API key</label>
        <select id="api_key_id" name="api_key_id">
            <option value="">All keys</option>
            @foreach ($keys as $id => $label)
                <option value="{{ $id }}" @selected(($filters['api_key_id'] ?? null) === $id)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
@endif
@if ($models !== [])
    <div class="field">
        <label for="model">Model</label>
        <select id="model" name="model">
            <option value="">All models</option>
            @foreach ($models as $tag)
                <option value="{{ $tag }}" @selected(($filters['model'] ?? null) === $tag)>{{ $tag }}</option>
            @endforeach
        </select>
    </div>
@endif
