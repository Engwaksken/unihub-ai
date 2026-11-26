@props(['project'])
<div class="page-header">
    <div>
        <p class="breadcrumb"><a href="{{ route('projects.index') }}">Projects</a>
            @if ($project->organizationName) <span aria-hidden="true">/</span> {{ $project->organizationName }} @endif
        </p>
        <h1>{{ $project->name }}</h1>
        <p class="muted"><code>{{ $project->slug }}</code> · {{ ucfirst($project->environment) }} · <x-status-badge :status="$project->status" /></p>
    </div>
</div>

<nav class="tabs" aria-label="Project sections">
    @foreach (\App\Support\Features::projectTabs($project->id) as $tab)
        <a href="{{ route($tab['route'], $project->id) }}" @class(['tab', 'is-active' => request()->routeIs($tab['route'])])
           @if (request()->routeIs($tab['route'])) aria-current="page" @endif>{{ $tab['label'] }}</a>
    @endforeach
</nav>
