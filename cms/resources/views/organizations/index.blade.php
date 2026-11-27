<x-layouts.app title="Organizations">
    <div class="page-header">
        <div>
            <h1>Organizations</h1>
            <p class="muted">Organizations group projects. Data is read live from the gateway.</p>
        </div>
        @can('organization.manage')
            <x-modal name="create-organization" title="New organization" trigger-label="New organization">
                <form method="POST" action="{{ route('organizations.store') }}" class="form">
                    @csrf
                    <x-idempotency-key />
                    <input type="hidden" name="_modal" value="create-organization">
                    <x-form-errors bag="createOrganization" />
                    <div class="field">
                        <label for="new-org-name">Name</label>
                        <input id="new-org-name" name="name" type="text" maxlength="120" required
                               value="{{ old('_modal') === 'create-organization' ? old('name') : '' }}"
                               @error('name', 'createOrganization') aria-invalid="true" @enderror>
                    </div>
                    <div class="dialog-actions">
                        <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create organization</button>
                    </div>
                </form>
            </x-modal>
        @endcan
    </div>

    @if ($stats)
        <dl class="metrics" aria-label="Organization statistics">
            <x-metric label="Organizations" :value="number_format($stats['total'])" />
            <x-metric label="Active" :value="number_format($stats['active'])" />
            <x-metric label="Archived" :value="number_format($stats['archived'])" />
            <x-metric label="Projects" :value="number_format($stats['projects'])" hint="Across all organizations" />
        </dl>
    @endif

    <form method="GET" action="{{ route('organizations.index') }}" class="filters" role="search" aria-label="Filter organizations">
        <div class="field">
            <label for="org-q">Search</label>
            <input id="org-q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="120">
        </div>
        <div class="field">
            <label for="org-status">Status</label>
            <select id="org-status" name="status">
                <option value="">Any</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                <option value="archived" @selected(($filters['status'] ?? '') === 'archived')>Archived</option>
            </select>
        </div>
        <div class="filters-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
            @if (! empty($filters['q']) || ! empty($filters['status']))
                <a href="{{ route('organizations.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    @if ($errors->any() && ! $errors->has('form'))
        <x-form-errors />
    @endif

    @if ($error)
        <x-gateway-unavailable :exception="$error" />
    @elseif ($page->isEmpty())
        <x-empty-state title="No organizations found" icon="building">
            @if (! empty($filters['q']) || ! empty($filters['status']))
                <p>No organization matches these filters.</p>
            @else
                <p>No organizations have been created yet.</p>
            @endif
        </x-empty-state>
    @else
        <div class="table-wrap">
            <table class="table">
                <caption class="visually-hidden">Organizations</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Projects</th>
                        <th scope="col">Created</th>
                        @can('organization.manage')<th scope="col"><span class="visually-hidden">Actions</span></th>@endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach ($page->items as $org)
                        <tr>
                            <th scope="row">
                                <a href="{{ route('projects.index', ['organization' => $org->id]) }}">{{ $org->name }}</a>
                            </th>
                            <td><x-status-badge :status="$org->status" /></td>
                            <td class="num">{{ $org->projectCount ?? '—' }}</td>
                            <td><x-time :value="$org->createdAt" /></td>
                            @can('organization.manage')
                                <td class="row-actions">
                                    <div x-data class="confirm">
                                        <button type="button" class="btn btn-secondary btn-sm" x-on:click="$refs.rename.showModal()" aria-haspopup="dialog">Rename</button>
                                        <dialog x-ref="rename" class="dialog" aria-labelledby="rename-{{ $loop->index }}-title">
                                            <form method="POST" action="{{ route('organizations.update', $org->id) }}">
                                                @csrf
                                                @method('PATCH')
                                                <x-idempotency-key />
                                                <h2 id="rename-{{ $loop->index }}-title" class="dialog-title">Rename organization</h2>
                                                <div class="field">
                                                    <label for="rename-{{ $loop->index }}">New name</label>
                                                    <input id="rename-{{ $loop->index }}" name="name" type="text" maxlength="120" required value="{{ $org->name }}">
                                                </div>
                                                <div class="dialog-actions">
                                                    <button type="button" class="btn btn-secondary" x-on:click="$refs.rename.close()">Cancel</button>
                                                    <button type="submit" class="btn btn-primary">Save name</button>
                                                </div>
                                            </form>
                                        </dialog>
                                    </div>
                                    @if ($org->isArchived())
                                        <form method="POST" action="{{ route('organizations.status', $org->id) }}">
                                            @csrf
                                            @method('PATCH')
                                            <x-idempotency-key />
                                            <input type="hidden" name="status" value="active">
                                            <button type="submit" class="btn btn-secondary btn-sm">Restore</button>
                                        </form>
                                    @else
                                        <x-confirm-dialog :title="'Archive '.$org->name.'?'" :action="route('organizations.status', $org->id)" method="PATCH"
                                                          trigger-label="Archive" confirm-label="Archive organization">
                                            <x-slot:fields><input type="hidden" name="status" value="archived"></x-slot:fields>
                                            Archived organizations stay visible for audit and reporting. You can restore it later.
                                        </x-confirm-dialog>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <nav class="pager" aria-label="Pagination">
            @if (! empty($filters['cursor']))
                <a class="btn btn-secondary btn-sm" href="{{ route('organizations.index', array_filter(['q' => $filters['q'] ?? null, 'status' => $filters['status'] ?? null])) }}">First page</a>
            @endif
            @if ($page->nextCursor)
                <a class="btn btn-secondary btn-sm" href="{{ route('organizations.index', array_filter(['q' => $filters['q'] ?? null, 'status' => $filters['status'] ?? null, 'cursor' => $page->nextCursor])) }}">Next page</a>
            @endif
        </nav>
    @endif
</x-layouts.app>
