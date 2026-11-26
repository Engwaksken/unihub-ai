<x-layouts.app title="Tasks">
    <div class="page-header">
        <div>
            <h1>Models &amp; tasks</h1>
            <p class="muted">Tasks are fixed system prompts. A new version starts as a draft; a published version is live and can no longer be changed.</p>
        </div>
        @can('task.manage')
            <a href="{{ route('tasks.create') }}" class="btn btn-primary">New task or version</a>
        @endcan
    </div>
    @include('models._tabs')
    <x-form-errors />

    @if ($error)
        <x-gateway-unavailable :exception="$error" />
    @elseif ($tasks->isEmpty())
        <x-empty-state title="No tasks yet" icon="cpu"><p>No task templates have been created.</p></x-empty-state>
    @else
        @foreach ($tasks->items as $task)
            @php
                $versions = is_array($task['versions'] ?? null) ? $task['versions'] : [];
                usort($versions, fn ($a, $b) => (int) ($b['version'] ?? 0) <=> (int) ($a['version'] ?? 0));
                $current = $task['current_version'] ?? null;
            @endphp
            <section class="card" aria-labelledby="task-{{ $loop->index }}">
                <div class="section-head">
                    <div>
                        <h2 id="task-{{ $loop->index }}"><code>{{ $task['key'] ?? '' }}</code></h2>
                        <p class="muted">{{ $task['description'] ?? '' }}</p>
                        <p class="small">Live version: {{ $current !== null ? 'v'.$current : 'none published yet' }}</p>
                    </div>
                    @can('task.manage')
                        <a class="btn btn-secondary btn-sm" href="{{ route('tasks.create', ['key' => $task['key'] ?? '']) }}">New version<span class="visually-hidden"> of {{ $task['key'] ?? '' }}</span></a>
                    @endcan
                </div>

                @foreach ($versions as $v)
                    @php
                        $published = ! empty($v['published_at']);
                        $model = $models[$v['model_id'] ?? ''] ?? null;
                    @endphp
                    <details class="version" @if ($loop->first) open @endif>
                        <summary>
                            <strong>v{{ $v['version'] ?? '?' }}</strong>
                            @if ($published && (int) ($v['version'] ?? 0) === (int) $current)
                                <span class="badge badge-success">Live</span>
                            @elseif ($published)
                                <span class="badge badge-muted">Published (superseded)</span>
                            @else
                                <span class="badge badge-warning">Draft</span>
                            @endif
                            <span class="muted small">· {{ $v['output_format'] ?? 'text' }} · cap {{ $v['output_cap'] ?? '?' }} tokens · {{ $model['tag'] ?? ($v['model_id'] ?? 'unknown model') }}</span>
                        </summary>
                        <dl class="details">
                            <div><dt>Created</dt><dd><x-time :value="$v['created_at'] ?? null" /> <span class="muted small">{{ $v['created_by'] ?? '' }}</span></dd></div>
                            <div><dt>Published</dt><dd>@if ($published) <x-time :value="$v['published_at']" /> @else Not published @endif</dd></div>
                        </dl>
                        <p class="small"><strong>System template</strong> @if ($published)<span class="muted">(read-only)</span>@endif</p>
                        <pre class="template">{{ $v['system_template'] ?? '' }}</pre>
                        @if (! $published)
                            @can('task.manage')
                                <x-confirm-dialog :title="'Publish '.($task['key'] ?? '').' v'.($v['version'] ?? '').'?'"
                                                  :action="route('tasks.publish', [$task['key'] ?? 'x', (int) ($v['version'] ?? 0)])"
                                                  trigger-label="Publish" trigger-class="btn btn-primary btn-sm" confirm-label="Publish version" confirm-class="btn btn-primary">
                                    This version becomes live for every project allowed to use this task, and it can no longer be edited. Its model must be enabled.
                                </x-confirm-dialog>
                            @endcan
                        @endif
                    </details>
                @endforeach
            </section>
        @endforeach
        <nav class="pager" aria-label="Pagination">
            @if ($cursor)<a class="btn btn-secondary btn-sm" href="{{ route('tasks.index') }}">First page</a>@endif
            @if ($tasks->nextCursor)<a class="btn btn-secondary btn-sm" href="{{ route('tasks.index', ['cursor' => $tasks->nextCursor]) }}">Next page</a>@endif
        </nav>
    @endif
</x-layouts.app>
