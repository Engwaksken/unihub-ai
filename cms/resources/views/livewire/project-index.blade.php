<div>
    <div class="page-header">
        <div>
            <h1>Projects</h1>
            <p class="muted">
                @if (\App\Authorization\Permissions::projectScope(auth()->user(), 'project.view') === null)
                    All projects registered on the gateway.
                @else
                    Projects you are assigned to.
                @endif
            </p>
        </div>
        @if ($canCreate)
            <a href="{{ route('projects.create') }}" class="btn btn-primary">New project</a>
        @endif
    </div>

    @if ($unassigned)
        <x-empty-state title="No projects assigned" icon="folder">
            <p>You are not assigned to any project yet. An owner can add you to projects from Users &amp; audit.</p>
        </x-empty-state>
    @else
        <div class="filters" role="search" aria-label="Filter projects">
            <div class="field">
                <label for="project-search">Search</label>
                <input id="project-search" type="search" wire:model.live.debounce.400ms="search" maxlength="120" placeholder="Name or slug">
            </div>
            <div class="field">
                <label for="project-status">Status</label>
                <select id="project-status" wire:model.live="status">
                    <option value="">Any</option>
                    @foreach (\App\Gateway\Data\Project::STATUSES as $option)
                        <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                    @endforeach
                </select>
            </div>
            @if ($canFilterByOrganization)
                <div class="field">
                    <label for="project-org">Organization</label>
                    <select id="project-org" wire:model.live="organizationId">
                        <option value="">Any</option>
                        @foreach ($organizations as $org)
                            <option value="{{ $org->id }}">{{ $org->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if ($filtered)
                <div class="filters-actions">
                    <button type="button" class="btn btn-ghost" wire:click="clearFilters">Clear filters</button>
                </div>
            @endif
        </div>

        <div class="loading-bar" wire:loading.delay role="status">
            <span>Loading projects…</span>
        </div>

        <div wire:loading.class="is-loading">
            @if ($error === 'unreachable')
                <x-gateway-unavailable />
            @elseif ($error === 'invalid-filter')
                <div class="alert alert-error" role="alert">The gateway rejected these filters. Clear them and try again.</div>
            @elseif ($projects === [])
                <x-empty-state title="No projects found" icon="folder">
                    @if ($filtered)
                        <p>No project matches these filters.</p>
                    @elseif ($canCreate)
                        <p>No projects have been registered yet.</p>
                    @else
                        <p>There are no projects to show.</p>
                    @endif
                </x-empty-state>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <caption class="visually-hidden">Projects</caption>
                        <thead>
                            <tr>
                                <th scope="col">Project</th>
                                <th scope="col">Organization</th>
                                <th scope="col">Environment</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="num">Daily limit</th>
                                <th scope="col">Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($projects as $project)
                                <tr wire:key="project-{{ $project->id }}">
                                    <th scope="row">
                                        <a href="{{ route('projects.show', $project->id) }}">{{ $project->name }}</a>
                                        <span class="cell-sub">{{ $project->slug }}</span>
                                    </th>
                                    <td>{{ $project->organizationName ?? '—' }}</td>
                                    <td>{{ ucfirst($project->environment) }}</td>
                                    <td><x-status-badge :status="$project->status" /></td>
                                    <td class="num">{{ $project->dailyLimit ?? '—' }}</td>
                                    <td><x-time :value="$project->updatedAt" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if (! $error && ($nextCursor || $previousCursors))
                <nav class="pager" aria-label="Pagination">
                    @if ($previousCursors)
                        <button type="button" class="btn btn-secondary btn-sm" wire:click="previousPage">Previous</button>
                    @endif
                    @if ($nextCursor)
                        <button type="button" class="btn btn-secondary btn-sm" wire:click="nextPage">Next</button>
                    @endif
                </nav>
            @endif
        </div>
    @endif
</div>
