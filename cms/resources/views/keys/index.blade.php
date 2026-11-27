<x-layouts.app title="API keys">
    <div class="page-header">
        <div>
            <h1>API keys</h1>
            <p class="muted">Key metadata across your projects, {{ \App\Http\Controllers\KeyIndexController::PER_PAGE }} projects per page. Secrets are never shown here; issue, rotate and revoke keys on each project's API keys tab.</p>
        </div>
    </div>

    @php($hasFilters = ($filters['q'] ?? '') !== '' || ! empty($filters['status']))
    @unless ($unassigned)
        @if ($stats)
            <dl class="metrics" aria-label="Key statistics">
                <x-metric label="Keys on this page" :value="number_format($stats['total'])" :hint="count($groups).' projects'" />
                <x-metric label="Active" :value="number_format($stats['active'])" />
                <x-metric label="Revoked or expired" :value="number_format($stats['inactive'])" />
                <x-metric label="Expiring within 14 days" :value="number_format($stats['expiring'])" hint="Rotate before they expire" />
                <x-metric label="Active, never used" :value="number_format($stats['never_used'])" hint="Candidates to revoke" />
            </dl>
        @endif
        <form method="GET" action="{{ route('keys.index') }}" class="filters" role="search" aria-label="Filter keys">
            <div class="field">
                <label for="key-q">Project</label>
                <input id="key-q" name="q" type="search" maxlength="120" placeholder="Project name or slug" value="{{ $filters['q'] ?? '' }}">
            </div>
            <div class="field">
                <label for="key-status">Key status</label>
                <select id="key-status" name="status">
                    <option value="">Any</option>
                    @foreach (['active' => 'Active', 'expired' => 'Expired', 'revoked' => 'Revoked'] as $v => $t)
                        <option value="{{ $v }}" @selected(($filters['status'] ?? '') === $v)>{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn-secondary">Apply</button>
                @if ($hasFilters) <a href="{{ route('keys.index') }}" class="btn btn-ghost">Clear</a> @endif
            </div>
        </form>
    @endunless

    @if ($unassigned)
        <x-empty-state title="No projects assigned" icon="key"><p>You are not assigned to any project.</p></x-empty-state>
    @elseif ($error)
        <x-gateway-unavailable :exception="$error" />
    @elseif ($groups === [])
        <x-empty-state :title="$hasFilters ? 'No keys match these filters' : 'No projects on this page'" icon="key"><p>{{ $hasFilters ? 'Try a different project name or status.' : 'There are no projects to show.' }}</p></x-empty-state>
    @else
        <div class="table-wrap">
            <table class="table table-compact">
                <caption class="visually-hidden">API keys by project</caption>
                <thead>
                    <tr>
                        <th scope="col">Project</th>
                        <th scope="col">Prefix</th>
                        <th scope="col">Label</th>
                        <th scope="col">Status</th>
                        <th scope="col">Expires</th>
                        <th scope="col">Last used</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups as $group)
                        @php($project = $group['project'])
                        @php($manage = \Illuminate\Support\Facades\Gate::allows('key.manage', $project->id))
                        @forelse ($group['keys'] as $key)
                            <tr>
                                <th scope="row">
                                    <a href="{{ route('projects.keys', $project->id) }}">{{ $project->name }}</a>
                                    <span class="cell-sub">{{ $project->slug }}@if ($loop->last && $group['more']) · more keys on the project page @endif</span>
                                </th>
                                <td><code>{{ $key['prefix'] ?? '' }}</code></td>
                                <td>{{ $key['label'] ?? '—' }}</td>
                                <td><x-status-badge :status="(string) ($key['status'] ?? 'unknown')" /></td>
                                <td>@if (! empty($key['expires_at'])) <x-time :value="$key['expires_at']" /> @else <span class="muted">Never</span> @endif</td>
                                <td>@if (! empty($key['last_used_at'])) <x-time :value="$key['last_used_at']" /> @else <span class="muted">Never used</span> @endif</td>
                                <td class="row-actions">
                                    <a class="btn btn-secondary btn-sm" href="{{ route('projects.keys', $project->id) }}">{{ $manage ? 'Manage' : 'View' }}<span class="visually-hidden"> keys for {{ $project->name }}</span></a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <th scope="row">
                                    <a href="{{ route('projects.keys', $project->id) }}">{{ $project->name }}</a>
                                    <span class="cell-sub">{{ $project->slug }}</span>
                                </th>
                                <td colspan="5" class="muted">No keys</td>
                                <td class="row-actions">
                                    <a class="btn btn-secondary btn-sm" href="{{ route('projects.keys', $project->id) }}">{{ $manage ? 'Issue key' : 'View' }}<span class="visually-hidden"> for {{ $project->name }}</span></a>
                                </td>
                            </tr>
                        @endforelse
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (! $error)
        <nav class="pager" aria-label="Pagination">
            @php($keep = array_filter(['q' => $filters['q'] ?? null, 'status' => $filters['status'] ?? null]))
            @if ($cursor)<a class="btn btn-secondary btn-sm" href="{{ route('keys.index', $keep) }}">First page</a>@endif
            @if ($next)<a class="btn btn-secondary btn-sm" href="{{ route('keys.index', $keep + ['cursor' => $next]) }}">Next projects</a>@endif
        </nav>
    @endif
</x-layouts.app>
