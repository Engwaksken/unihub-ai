<x-layouts.app title="Audit log">
    <div class="page-header">
        <div>
            @can('staff.manage')
                <p class="breadcrumb"><a href="{{ route('users.index') }}">Users &amp; audit</a></p>
            @endcan
            <h1>Audit log</h1>
            <p class="muted">
                CMS events (sign-ins, invitations, roles, sessions, portal settings) and gateway events (projects, keys, jobs, models, settings) in one list, labelled by source.
                @if ($scoped) Showing events for your assigned projects only. @endif
            </p>
        </div>
        <a class="btn btn-secondary" href="{{ route('audit.export', array_filter(\Illuminate\Support\Arr::except($filters, 'cursor') + ['project_id' => $projectId])) }}">Export CSV</a>
    </div>

    <form method="GET" action="{{ route('audit.index') }}" class="filters" aria-label="Filter audit events">
        <div class="field">
            <label for="source">Source</label>
            <select id="source" name="source">
                @foreach (['all' => 'All sources', 'cms' => 'CMS', 'gateway' => 'Gateway'] as $value => $text)
                    <option value="{{ $value }}" @selected($filters['source'] === $value)>{{ $text }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="action">Action</label>
            <input id="action" name="action" type="text" maxlength="64" value="{{ $filters['action'] ?? '' }}" placeholder="e.g. key.issued" pattern="[a-z_.]+">
        </div>
        @if ($projects !== [])
            <div class="field">
                <label for="project_id">Project</label>
                <select id="project_id" name="project_id">
                    <option value="">Any</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}" @selected($projectId === $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="field">
            <label for="from">From</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}">
        </div>
        <div class="field">
            <label for="to">To</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}">
        </div>
        <div class="filters-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
            <a href="{{ route('audit.index') }}" class="btn btn-ghost">Clear</a>
        </div>
    </form>
    <x-form-errors />

    @if ($gatewayError)
        <div class="alert alert-warning" role="status">
            <p class="alert-title">Gateway audit events unavailable</p>
            <p>The gateway could not be reached, so only CMS events are listed below.</p>
        </div>
    @endif
    @if ($filters['source'] === 'all')
        <p class="muted small">Showing the newest {{ \App\Http\Controllers\AuditController::MERGED_LIMIT }} events of each source, merged by time. Choose a single source to page further back.</p>
    @endif

    @if ($rows === [])
        <x-empty-state title="No audit events" icon="shield"><p>Nothing was recorded for these filters.</p></x-empty-state>
    @else
        <div class="table-wrap">
            <table class="table table-compact">
                <caption class="visually-hidden">Audit events</caption>
                <thead>
                    <tr>
                        <th scope="col">Time ({{ \App\Support\DisplayTime::zone() }})</th>
                        <th scope="col">Source</th>
                        <th scope="col">Action</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Target / project</th>
                        <th scope="col">Request ID</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td><x-time :value="$row['created_at']" format="j M Y, H:i:s" /></td>
                            <td><span @class(['badge', 'badge-neutral' => $row['source'] === 'cms', 'badge-muted' => $row['source'] === 'gateway'])>{{ $row['source'] === 'cms' ? 'CMS' : 'Gateway' }}</span></td>
                            <td><code>{{ $row['action'] }}</code></td>
                            <td>{{ $row['actor_name'] ?? ($row['actor_ref'] ?? '—') }}
                                @if ($row['actor_ref'] && $row['actor_name']) <span class="cell-sub">{{ $row['actor_ref'] }}</span> @endif
                                @if ($row['ip_address']) <span class="cell-sub">{{ $row['ip_address'] }}</span> @endif</td>
                            <td>
                                @if ($row['target_type']) <span class="cell-sub">{{ $row['target_type'] }} {{ $row['target_id'] }}</span> @endif
                                @if ($row['project_id']) <span class="cell-sub">project {{ $row['project_id'] }}</span> @endif
                                @if ($row['metadata'])
                                    <details>
                                        <summary>Details</summary>
                                        <pre class="metadata">{{ json_encode($row['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                            </td>
                            <td><code class="small">{{ $row['request_id'] }}</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($cmsPaginator)
        {{ $cmsPaginator->links() }}
    @endif
    @if ($gatewayNext || ($filters['source'] === 'gateway' && ! empty($filters['cursor'])))
        <nav class="pager" aria-label="Pagination">
            @if (! empty($filters['cursor']))
                <a class="btn btn-secondary btn-sm" href="{{ route('audit.index', array_filter(\Illuminate\Support\Arr::except($filters, 'cursor') + ['project_id' => $projectId])) }}">Newest</a>
            @endif
            @if ($gatewayNext)
                <a class="btn btn-secondary btn-sm" href="{{ route('audit.index', array_filter(\Illuminate\Support\Arr::except($filters, 'cursor') + ['project_id' => $projectId, 'cursor' => $gatewayNext])) }}">Older</a>
            @endif
        </nav>
    @endif
</x-layouts.app>
