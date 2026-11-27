{{--
    Job metadata table. Expects $page (GatewayPage), $projects (uuid => Project), $showProject (bool),
    $paginationRoute + $paginationParams for the next-page link. Payloads are never shown.
--}}
@php use App\Support\Fmt; @endphp
@if ($page->isEmpty())
    <x-empty-state title="No jobs found" icon="queue"><p>No job matches these filters.</p></x-empty-state>
@else
    <div class="table-wrap">
        <table class="table table-compact">
            <caption class="visually-hidden">Jobs (metadata only)</caption>
            <thead>
                <tr>
                    <th scope="col">Job</th>
                    @if ($showProject)<th scope="col">Project</th>@endif
                    <th scope="col">Task / model</th>
                    <th scope="col">State</th>
                    <th scope="col" class="num">Queue</th>
                    <th scope="col" class="num">Inference</th>
                    <th scope="col" class="num">Tokens in / out</th>
                    <th scope="col">Error</th>
                    <th scope="col">Queued</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($page->items as $job)
                    @php
                        $jobProject = strtolower((string) ($job['project_id'] ?? ''));
                        $state = (string) ($job['state'] ?? 'unknown');
                        $canOperate = \Illuminate\Support\Facades\Gate::allows('job.manage', $jobProject);
                    @endphp
                    <tr>
                        <th scope="row"><code class="small">{{ $job['id'] ?? '' }}</code>
                            @if (! empty($job['retry_of'])) <span class="cell-sub">retry of {{ $job['retry_of'] }}</span> @endif
                            <span class="cell-sub">attempts {{ Fmt::int($job['attempts'] ?? null) }}</span></th>
                        @if ($showProject)
                            <td>{{ isset($projects[$jobProject]) ? $projects[$jobProject]->name : $jobProject }}</td>
                        @endif
                        <td>{{ $job['task'] ?? '—' }}@if (isset($job['task_version'])) <span class="muted">v{{ $job['task_version'] }}</span>@endif
                            <span class="cell-sub">{{ $job['model'] ?? '' }}</span></td>
                        <td><span @class(['badge', 'badge-success' => $state === 'completed', 'badge-warning' => in_array($state, ['queued', 'running']), 'badge-error' => $state === 'failed', 'badge-muted' => in_array($state, ['cancelled', 'expired'])])>{{ ucfirst($state) }}</span></td>
                        <td class="num">{{ Fmt::ms($job['queue_ms'] ?? null, '—') }}</td>
                        <td class="num">{{ Fmt::ms($job['inference_ms'] ?? null, '—') }}</td>
                        <td class="num">{{ Fmt::tokens($job['tokens_in'] ?? null) }} / {{ Fmt::tokens($job['tokens_out'] ?? null) }}</td>
                        <td>@if (! empty($job['error_code'])) <code>{{ $job['error_code'] }}</code> @else <span class="muted">—</span> @endif</td>
                        <td><x-time :value="$job['queued_at'] ?? ($job['created_at'] ?? null)" format="j M, H:i:s" />
                            @if (! empty($job['finished_at'])) <span class="cell-sub">finished <x-time :value="$job['finished_at']" format="H:i:s" /></span> @endif</td>
                        <td class="row-actions">
                            @if ($canOperate && $state === 'queued')
                                <x-confirm-dialog title="Cancel this queued job?" :action="route('jobs.cancel', $job['id'] ?? '')"
                                                  trigger-label="Cancel" confirm-label="Cancel job">
                                    <x-slot:fields><input type="hidden" name="project_id" value="{{ $jobProject }}"></x-slot:fields>
                                    The job is removed from the queue and will not run. The client sees it as cancelled.
                                </x-confirm-dialog>
                            @endif
                            {{-- Older gateways omit error_transient; then the gateway alone decides. --}}
                            @if ($canOperate && $state === 'failed' && ($job['error_transient'] ?? true) !== false)
                                <div x-data class="confirm">
                                    <button type="button" class="btn btn-secondary btn-sm" x-on:click="$refs.retry.showModal()" aria-haspopup="dialog">Retry</button>
                                    <dialog x-ref="retry" class="dialog" aria-labelledby="retry-{{ $loop->index }}-title">
                                        <form method="POST" action="{{ route('jobs.retry', $job['id'] ?? '') }}">
                                            @csrf
                                            <x-idempotency-key />
                                            <input type="hidden" name="project_id" value="{{ $jobProject }}">
                                            <h2 id="retry-{{ $loop->index }}-title" class="dialog-title">Retry failed job</h2>
                                            <p class="dialog-body">A new job is queued with the same input. Only jobs that failed with a temporary error and whose input is still retained can be retried; the gateway checks this.</p>
                                            <div class="field">
                                                <label for="reason-{{ $loop->index }}">Reason (required, recorded in the audit log)</label>
                                                <textarea id="reason-{{ $loop->index }}" name="reason" rows="2" minlength="3" maxlength="200" required></textarea>
                                            </div>
                                            <div class="dialog-actions">
                                                <button type="button" class="btn btn-secondary" x-on:click="$refs.retry.close()">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Queue retry</button>
                                            </div>
                                        </form>
                                    </dialog>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
<nav class="pager" aria-label="Pagination">
    @if (! empty($paginationParams['cursor']))
        <a class="btn btn-secondary btn-sm" href="{{ route($paginationRoute, \Illuminate\Support\Arr::except($paginationParams, 'cursor')) }}">First page</a>
    @endif
    @if ($page->nextCursor)
        <a class="btn btn-secondary btn-sm" href="{{ route($paginationRoute, array_merge($paginationParams, ['cursor' => $page->nextCursor])) }}">Next page</a>
    @endif
</nav>
