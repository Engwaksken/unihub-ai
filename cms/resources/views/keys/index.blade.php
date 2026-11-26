<x-layouts.app title="API keys">
    <div class="page-header">
        <div>
            <h1>API keys</h1>
            <p class="muted">Key metadata across your projects, {{ \App\Http\Controllers\KeyIndexController::PER_PAGE }} projects per page. Secrets are never shown here; issue, rotate and revoke keys on each project's API keys tab.</p>
        </div>
    </div>

    @if ($unassigned)
        <x-empty-state title="No projects assigned" icon="key"><p>You are not assigned to any project.</p></x-empty-state>
    @elseif ($error)
        <x-gateway-unavailable :exception="$error" />
    @elseif ($groups === [])
        <x-empty-state title="No projects on this page" icon="key"><p>There are no projects to show.</p></x-empty-state>
    @else
        @foreach ($groups as $group)
            @php($project = $group['project'])
            <section class="card" aria-labelledby="kp-{{ $loop->index }}">
                <div class="section-head">
                    <h2 id="kp-{{ $loop->index }}">{{ $project->name }} <span class="muted small">{{ $project->slug }}</span></h2>
                    <a class="btn btn-secondary btn-sm" href="{{ route('projects.keys', $project->id) }}">
                        {{ \Illuminate\Support\Facades\Gate::allows('key.manage', $project->id) ? 'Manage keys' : 'View keys' }}<span class="visually-hidden"> for {{ $project->name }}</span>
                    </a>
                </div>
                @if ($group['keys'] === [])
                    <p class="muted">No keys.</p>
                @else
                    <div class="table-wrap">
                        <table class="table table-compact">
                            <caption class="visually-hidden">Keys for {{ $project->name }}</caption>
                            <thead><tr><th scope="col">Prefix</th><th scope="col">Label</th><th scope="col">Status</th><th scope="col">Expires</th><th scope="col">Last used</th></tr></thead>
                            <tbody>
                                @foreach ($group['keys'] as $key)
                                    <tr>
                                        <th scope="row"><code>{{ $key['prefix'] ?? '' }}</code></th>
                                        <td>{{ $key['label'] ?? '—' }}</td>
                                        <td><x-status-badge :status="(string) ($key['status'] ?? 'unknown')" /></td>
                                        <td>@if (! empty($key['expires_at'])) <x-time :value="$key['expires_at']" /> @else <span class="muted">Never</span> @endif</td>
                                        <td>@if (! empty($key['last_used_at'])) <x-time :value="$key['last_used_at']" /> @else <span class="muted">Never used</span> @endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($group['more']) <p class="muted small">More keys exist; open the project to see all of them.</p> @endif
                @endif
            </section>
        @endforeach
    @endif

    @if (! $error)
        <nav class="pager" aria-label="Pagination">
            @if ($cursor)<a class="btn btn-secondary btn-sm" href="{{ route('keys.index') }}">First page</a>@endif
            @if ($next)<a class="btn btn-secondary btn-sm" href="{{ route('keys.index', ['cursor' => $next]) }}">Next projects</a>@endif
        </nav>
    @endif
</x-layouts.app>
