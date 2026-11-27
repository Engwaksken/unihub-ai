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
            {{-- wire:ignore keeps the dialog open while Livewire re-renders the list behind it. --}}
            <div wire:ignore>
                <x-modal name="create-project" title="New project" trigger-label="New project" size="lg">
                    @if ($error === 'unreachable')
                        <x-gateway-unavailable />
                    @elseif ($activeOrganizations === [])
                        <p class="dialog-body">A project belongs to an organization. Create or restore an organization first.</p>
                        <div class="dialog-actions">
                            @can('organization.view') <a class="btn btn-secondary" href="{{ route('organizations.index') }}">Go to organizations</a> @endcan
                        </div>
                    @else
                        <form method="POST" action="{{ route('projects.store') }}" class="form" x-data="{ busy: false }" x-on:submit="busy = true">
                            @csrf
                            <x-idempotency-key />
                            <input type="hidden" name="_modal" value="create-project">
                            <x-form-errors />
                            @include('projects._create_fields', ['organizations' => $activeOrganizations])
                            <div class="dialog-actions">
                                <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Cancel</button>
                                <button type="submit" class="btn btn-primary" x-bind:disabled="busy">Create project</button>
                            </div>
                        </form>
                    @endif
                </x-modal>
            </div>
        @endif
    </div>

    @if ($stats)
        <dl class="metrics" aria-label="Project statistics">
            <x-metric label="Projects" :value="number_format($stats['total'])" :hint="number_format($stats['production']).' in production'" />
            <x-metric label="Active" :value="number_format($stats['active'] ?? 0)" />
            <x-metric label="Disabled" :value="number_format($stats['disabled'] ?? 0)" hint="Requests refused" />
            <x-metric label="Archived" :value="number_format($stats['archived'] ?? 0)" />
        </dl>
    @endif

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

        @php($canBulk = $bulk['status'] || $bulk['environment'] || $bulk['quotas'])
        @if ($canBulk && ! $error)
            {{-- wire:ignore keeps the toolbar's dialogs and typed values while the list re-renders. --}}
            <div wire:ignore>
                <x-bulk-bar id="bulk-projects" :action="route('projects.bulk')" noun="projects">
                    <x-bulk-dialog action="edit" title="Edit selected projects" trigger-label="Edit" confirm-label="Apply changes" size="lg">
                        <p class="dialog-body">Only the fields you fill in are changed; empty fields keep each project's current value. Projects you may not change are skipped.</p>
                        <div class="form-grid">
                            @if ($bulk['status'])
                                <div class="field">
                                    <label for="bulk-status">Status</label>
                                    <select id="bulk-status" name="status">
                                        <option value="">No change</option>
                                        @foreach (\App\Gateway\Data\Project::STATUSES as $option)
                                            <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            @if ($bulk['environment'])
                                <div class="field">
                                    <label for="bulk-environment">Environment</label>
                                    <select id="bulk-environment" name="environment">
                                        <option value="">No change</option>
                                        @foreach (\App\Gateway\Data\Project::ENVIRONMENTS as $option)
                                            <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        </div>
                        @if ($bulk['quotas'])
                            <div class="form-grid form-grid-3">
                                <div class="field">
                                    <label for="bulk-daily">Daily limit</label>
                                    <input id="bulk-daily" name="daily_limit" type="number" min="0" max="1000000" placeholder="No change">
                                </div>
                                <div class="field">
                                    <label for="bulk-rpm">Requests per minute</label>
                                    <input id="bulk-rpm" name="rpm_limit" type="number" min="0" max="10000" placeholder="No change">
                                </div>
                                <div class="field">
                                    <label for="bulk-queue">Queue limit</label>
                                    <input id="bulk-queue" name="queue_limit" type="number" min="0" max="8" placeholder="No change">
                                </div>
                            </div>
                        @endif
                    </x-bulk-dialog>
                    @if ($bulk['status'])
                        <x-bulk-dialog action="delete" title="Delete selected projects" trigger-label="Delete" trigger-class="btn btn-danger btn-sm" confirm-label="Archive projects" confirm-class="btn btn-danger">
                            <p class="dialog-body">The selected projects are archived: their API keys stop working and requests are refused. Jobs, keys, usage and the audit trail are kept, so a project can be restored later by setting its status back to active.</p>
                        </x-bulk-dialog>
                    @endif
                </x-bulk-bar>
            </div>
        @endif

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
                                @if ($canBulk)<th scope="col" class="col-check"><x-bulk-check form="bulk-projects" label="Select all projects on this page" /></th>@endif
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
                                    @if ($canBulk)<td class="col-check"><x-bulk-check form="bulk-projects" :value="$project->id" :label="'Select '.$project->name" /></td>@endif
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
