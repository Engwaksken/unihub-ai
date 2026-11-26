<x-layouts.app :title="$project->name.' · API keys'">
    <x-project-header :project="$project" />
    <x-form-errors />

    @if ($canManage)
        <section class="card" aria-labelledby="issue-heading">
            <h2 id="issue-heading">Issue a new key</h2>
            @if ($project->status !== 'active')
                <p class="muted">Keys can only be issued for active projects. This project is {{ $project->status }}.</p>
            @else
                <p class="field-help">The secret is shown once, on the next page. It cannot be shown again; store it in the client backend's secret store (never in a mobile app).</p>
                <form method="POST" action="{{ route('projects.keys.store', $project->id) }}" class="form form-inline">
                    @csrf
                    <x-idempotency-key />
                    <div class="field">
                        <label for="label">Label <span class="optional">(optional)</span></label>
                        <input id="label" name="label" type="text" maxlength="80" value="{{ old('label') }}" placeholder="e.g. production backend">
                    </div>
                    <div class="field">
                        <label for="expires_on">Expires on <span class="optional">(optional)</span></label>
                        <input id="expires_on" name="expires_on" type="date" value="{{ old('expires_on') }}" min="{{ now(\App\Support\DisplayTime::zone())->addDay()->format('Y-m-d') }}">
                    </div>
                    <button type="submit" class="btn btn-primary">Issue key</button>
                </form>
            @endif
        </section>
    @endif

    <section aria-labelledby="keys-heading">
        <h2 id="keys-heading">Keys</h2>
        @if ($keys->isEmpty())
            <x-empty-state title="No keys yet" icon="key"><p>This project has no API keys.</p></x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <caption class="visually-hidden">API keys for {{ $project->name }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Prefix</th>
                            <th scope="col">Label</th>
                            <th scope="col">Status</th>
                            <th scope="col">Created</th>
                            <th scope="col">Expires</th>
                            <th scope="col">Last used</th>
                            @if ($canManage)<th scope="col"><span class="visually-hidden">Actions</span></th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($keys->items as $key)
                            @php($status = (string) ($key['status'] ?? 'unknown'))
                            <tr>
                                <th scope="row"><code>{{ $key['prefix'] ?? '' }}</code>
                                    <span class="cell-sub">{{ $key['environment'] ?? '' }}</span></th>
                                <td>{{ $key['label'] ?? '—' }}
                                    @if (! empty($key['rotated_from_id'])) <span class="cell-sub">rotation of an earlier key</span> @endif</td>
                                <td><x-status-badge :status="$status" /></td>
                                <td><x-time :value="$key['created_at'] ?? null" /></td>
                                <td>@if (! empty($key['expires_at'])) <x-time :value="$key['expires_at']" /> @else <span class="muted">Never</span> @endif</td>
                                <td>@if (! empty($key['last_used_at'])) <x-time :value="$key['last_used_at']" /> @else <span class="muted">Never used</span> @endif</td>
                                @if ($canManage)
                                    <td class="row-actions">
                                        @if ($status === 'active')
                                            <div x-data class="confirm">
                                                <button type="button" class="btn btn-secondary btn-sm" x-on:click="$refs.rotate.showModal()" aria-haspopup="dialog">Rotate</button>
                                                <dialog x-ref="rotate" class="dialog" aria-labelledby="rotate-{{ $loop->index }}-title">
                                                    <form method="POST" action="{{ route('projects.keys.store', $project->id) }}">
                                                        @csrf
                                                        <x-idempotency-key />
                                                        <input type="hidden" name="rotates_key_id" value="{{ $key['id'] ?? '' }}">
                                                        <h2 id="rotate-{{ $loop->index }}-title" class="dialog-title">Rotate key {{ $key['prefix'] ?? '' }}</h2>
                                                        <p class="dialog-body">A new key is issued and shown once. The old key keeps working for the overlap period, then expires.</p>
                                                        <div class="field">
                                                            <label for="overlap-{{ $loop->index }}">Overlap (days, 0–30)</label>
                                                            <input id="overlap-{{ $loop->index }}" name="overlap_days" type="number" min="0" max="30" value="7" required>
                                                        </div>
                                                        <div class="field">
                                                            <label for="rlabel-{{ $loop->index }}">Label for the new key</label>
                                                            <input id="rlabel-{{ $loop->index }}" name="label" type="text" maxlength="80" value="{{ $key['label'] ?? '' }}">
                                                        </div>
                                                        <div class="dialog-actions">
                                                            <button type="button" class="btn btn-secondary" x-on:click="$refs.rotate.close()">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">Rotate key</button>
                                                        </div>
                                                    </form>
                                                </dialog>
                                            </div>
                                        @endif
                                        @if ($status !== 'revoked')
                                            <x-confirm-dialog :title="'Revoke key '.($key['prefix'] ?? '').'?'" :action="route('projects.keys.revoke', [$project->id, $key['id'] ?? ''])"
                                                              trigger-label="Revoke" confirm-label="Revoke key">
                                                Requests using this key are rejected immediately. This cannot be undone.
                                            </x-confirm-dialog>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <nav class="pager" aria-label="Pagination">
                @if ($cursor)<a class="btn btn-secondary btn-sm" href="{{ route('projects.keys', $project->id) }}">First page</a>@endif
                @if ($keys->nextCursor)<a class="btn btn-secondary btn-sm" href="{{ route('projects.keys', [$project->id, 'cursor' => $keys->nextCursor]) }}">Next page</a>@endif
            </nav>
        @endif
    </section>
</x-layouts.app>
