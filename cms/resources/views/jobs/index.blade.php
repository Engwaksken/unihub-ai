<x-layouts.app title="Jobs & queue">
    <div class="page-header">
        <div>
            <h1>Jobs &amp; queue</h1>
            <p class="muted">Job metadata only. Prompts and outputs are never shown in the portal.</p>
        </div>
    </div>

    @include('jobs._filters', ['action' => route('jobs.index')])
    <x-form-errors />

    @if ($error)
        <x-gateway-unavailable :exception="$error" />
    @else
        @include('jobs._table', [
            'showProject' => true,
            'paginationRoute' => 'jobs.index',
            'paginationParams' => array_filter(['project_id' => $projectId] + \Illuminate\Support\Arr::only($filters, ['state', 'from', 'to', 'cursor'])),
        ])
    @endif
</x-layouts.app>
