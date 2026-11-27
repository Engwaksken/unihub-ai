<x-layouts.app title="Users">
    @php
        $hasFilters = ($filters['q'] ?? '') !== '' || ! empty($filters['role']) || ! empty($filters['status']);
        $failed = session('invitation_mail_failed');
    @endphp
    <div class="page-header">
        <div>
            <h1>Users &amp; audit</h1>
            <p class="muted">Staff accounts, roles and project assignments.</p>
        </div>
        <div class="actions-row">
            <a href="{{ route('audit.index') }}" class="btn btn-secondary">Audit log</a>
            <x-modal name="invite" title="Invite staff" trigger-label="Invite staff" size="lg"
                     :description="'The invitee gets a single-use link (valid '.(int) config('cms.invitation_ttl_hours', 24).' hours) to set a password, then must set up two-factor authentication.'">
                <form method="POST" action="{{ route('users.store') }}" class="form">
                    @csrf
                    <input type="hidden" name="_modal" value="invite">
                    @if (old('_modal') === 'invite') <x-form-errors /> @endif
                    <x-tabs name="invite" aria-label="Invitation" :tabs="['details' => 'Details', 'roles' => 'Roles', 'projects' => 'Projects']"
                            :fields="old('_modal') === 'invite' ? ['details' => ['name', 'email'], 'roles' => ['roles', 'roles.*'], 'projects' => ['memberships', 'memberships.*']] : []">
                        <x-tab-panel tabs="invite" key="details">
                            <div class="form-grid">
                                <div class="field">
                                    <label for="invite-name">Full name</label>
                                    <input id="invite-name" name="name" type="text" maxlength="120" required value="{{ old('_modal') === 'invite' ? old('name') : '' }}">
                                    @if (old('_modal') === 'invite') @error('name') <p class="field-error">{{ $message }}</p> @enderror @endif
                                </div>
                                <div class="field">
                                    <label for="invite-email">E-mail address</label>
                                    <input id="invite-email" name="email" type="email" maxlength="190" required value="{{ old('_modal') === 'invite' ? old('email') : '' }}">
                                    @if (old('_modal') === 'invite') @error('email') <p class="field-error">{{ $message }}</p> @enderror @endif
                                </div>
                            </div>
                        </x-tab-panel>
                        <x-tab-panel tabs="invite" key="roles">
                            @include('users._access', ['section' => 'roles', 'idPrefix' => 'invite-', 'useOld' => old('_modal') === 'invite', 'selectedRoles' => old('_modal') === 'invite' ? old('roles', []) : [], 'currentMemberships' => []])
                        </x-tab-panel>
                        <x-tab-panel tabs="invite" key="projects">
                            @include('users._access', ['section' => 'projects', 'idPrefix' => 'invite-', 'useOld' => old('_modal') === 'invite', 'selectedRoles' => [], 'currentMemberships' => []])
                        </x-tab-panel>
                    </x-tabs>
                    <div class="dialog-actions">
                        <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Send invitation</button>
                    </div>
                </form>
            </x-modal>
        </div>
    </div>

    @if ($failed)
        <div class="alert alert-warning" role="alert">
            <p class="alert-title">The account for {{ $failed['email'] }} was created, but the invitation e-mail could not be sent.</p>
            <p>Mail server said: <code>{{ $failed['error'] }}</code>. Check the <code>MAIL_*</code> settings in the server's <code>.env</code>, then use <strong>Resend invite</strong>.</p>
            <p>Or give this single-use link to {{ $failed['email'] }} privately (it is shown only once):</p>
            <div class="invite-link" x-data="{ copied: false }">
                <input type="text" readonly value="{{ $failed['link'] }}" aria-label="Invitation link" x-ref="link" x-on:focus="$el.select()">
                <button type="button" class="btn btn-secondary btn-sm" x-on:click="navigator.clipboard.writeText($refs.link.value).then(() => copied = true)">
                    <span x-show="! copied">Copy link</span><span x-show="copied" x-cloak>Copied</span>
                </button>
            </div>
        </div>
    @endif

    <dl class="metrics" aria-label="Staff statistics">
        <x-metric label="Staff accounts" :value="number_format($stats['total'])" :hint="$stats['online'].' signed in now'" />
        <x-metric label="Active" :value="number_format($stats['active'])" hint="Password and 2FA set" />
        <x-metric label="Invitations pending" :value="number_format($stats['invited'])" hint="Link not used yet" />
        <x-metric label="2FA pending" :value="number_format($stats['mfa-pending'])" hint="Must finish authenticator setup" />
        <x-metric label="Disabled" :value="number_format($stats['disabled'])" />
    </dl>

    <form method="GET" action="{{ route('users.index') }}" class="filters" role="search" aria-label="Filter staff">
        <div class="field">
            <label for="user-q">Search</label>
            <input id="user-q" name="q" type="search" maxlength="120" placeholder="Name or e-mail" value="{{ $filters['q'] ?? '' }}">
        </div>
        <div class="field">
            <label for="user-role">Role</label>
            <select id="user-role" name="role">
                <option value="">Any role</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->value }}" @selected(($filters['role'] ?? '') === $role->value)>{{ $role->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="user-status">Status</label>
            <select id="user-status" name="status">
                <option value="">Any status</option>
                @foreach (\App\Http\Controllers\UserController::STATUSES as $value => $text)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $text }}</option>
                @endforeach
            </select>
        </div>
        <div class="filters-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
            @if ($hasFilters)
                <a href="{{ route('users.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    @if ($errors->any() && ! in_array(old('_modal'), ['invite'], true) && ! str_starts_with((string) old('_modal'), 'edit-'))
        <x-form-errors />
    @endif

    @if ($users->isEmpty())
        <x-empty-state :title="$hasFilters ? 'No staff match these filters' : 'No staff yet'" icon="users">
            <p>{{ $hasFilters ? 'Try a different search or clear the filters.' : 'Invite your first colleague.' }}</p>
        </x-empty-state>
    @else
        <p class="muted small">Showing {{ $users->firstItem() }}–{{ $users->lastItem() }} of {{ $users->total() }}</p>
        <div class="table-wrap">
            <table class="table">
                <caption class="visually-hidden">Staff accounts</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Roles</th>
                        <th scope="col" class="num">Projects</th>
                        <th scope="col">Status</th>
                        <th scope="col">Last sign-in</th>
                        <th scope="col" class="num">Sessions</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        @php
                            $status = match (true) {
                                $user->isDisabled() => 'disabled',
                                $user->isSystem() => 'system',
                                ! $user->hasSetPassword() => 'invited',
                                ! $user->hasConfirmedTwoFactor() => 'mfa-pending',
                                default => 'active',
                            };
                            $session = $sessions[$user->id] ?? null;
                            $modal = 'edit-'.$user->id;
                            $isOpen = old('_modal') === $modal;
                        @endphp
                        <tr>
                            <th scope="row">
                                {{ $user->name }}
                                <span class="cell-sub">{{ $user->email }}</span>
                            </th>
                            <td>{{ collect($user->roles())->map->label()->join(', ') }}</td>
                            <td class="num">{{ $user->memberships->count() }}</td>
                            <td><x-status-badge :status="$status" /></td>
                            <td><x-time :value="$user->last_login_at" /></td>
                            <td class="num">
                                {{ $session->total ?? 0 }}
                                @if ($session)
                                    <span class="cell-sub">last active <x-time :value="\Illuminate\Support\Carbon::createFromTimestamp($session->last_activity)" /></span>
                                @endif
                            </td>
                            <td class="row-actions">
                                @unless ($user->isSystem())
                                    <x-modal :name="$modal" :title="'Edit '.$user->name" size="lg" :description="$user->email">
                                        <x-slot:trigger>
                                            <button type="button" class="btn btn-secondary btn-sm" x-on:click="$refs.dialog.showModal()" aria-haspopup="dialog">Edit<span class="visually-hidden"> {{ $user->name }}</span></button>
                                        </x-slot:trigger>
                                        <form method="POST" action="{{ route('users.update', $user) }}" class="form">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="_modal" value="{{ $modal }}">
                                            @if ($isOpen) <x-form-errors /> @endif
                                            <x-tabs :name="$modal" aria-label="Staff access" :tabs="['details' => 'Details', 'roles' => 'Roles', 'projects' => 'Projects ('.$user->memberships->count().')']"
                                                    :fields="$isOpen ? ['details' => ['name'], 'roles' => ['roles', 'roles.*'], 'projects' => ['memberships', 'memberships.*']] : []">
                                                <x-tab-panel :tabs="$modal" key="details">
                                                    <div class="field">
                                                        <label for="{{ $modal }}-name">Full name</label>
                                                        <input id="{{ $modal }}-name" name="name" type="text" maxlength="120" required value="{{ $isOpen ? old('name', $user->name) : $user->name }}">
                                                        @if ($isOpen) @error('name') <p class="field-error">{{ $message }}</p> @enderror @endif
                                                    </div>
                                                </x-tab-panel>
                                                <x-tab-panel :tabs="$modal" key="roles">
                                                    @include('users._access', ['section' => 'roles', 'idPrefix' => $modal.'-', 'useOld' => $isOpen, 'selectedRoles' => $isOpen ? old('roles', $user->roleValues()) : $user->roleValues(), 'currentMemberships' => []])
                                                </x-tab-panel>
                                                <x-tab-panel :tabs="$modal" key="projects">
                                                    @include('users._access', ['section' => 'projects', 'idPrefix' => $modal.'-', 'useOld' => $isOpen, 'selectedRoles' => [], 'currentMemberships' => $user->memberships->mapWithKeys(fn ($m) => [strtolower($m->gateway_project_uuid) => $m->role->value])->all()])
                                                </x-tab-panel>
                                            </x-tabs>
                                            <p class="field-help">Changes apply from the user’s next request; gateway permissions are re-signed on every call.</p>
                                            <div class="dialog-actions">
                                                <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Save access</button>
                                            </div>
                                        </form>
                                    </x-modal>
                                @endunless
                                @if ($status === 'invited')
                                    <form method="POST" action="{{ route('users.invitation.resend', $user) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-secondary btn-sm">Resend invite</button>
                                    </form>
                                @endif
                                @if (($session->total ?? 0) > 0)
                                    <x-confirm-dialog :title="'Sign '.$user->name.' out everywhere?'" :action="route('users.sessions.revoke', $user)" method="DELETE"
                                                      trigger-label="Revoke sessions" confirm-label="Revoke all sessions">
                                        All of this user’s active sessions end immediately. They can sign in again with their password and authenticator.
                                    </x-confirm-dialog>
                                @endif
                                @if ($user->isSystem())
                                    {{-- The scheduler's account: never disabled from here (see StaffDirectory::disable). --}}
                                    @if ($user->isDisabled())
                                        <form method="POST" action="{{ route('users.enable', $user) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-secondary btn-sm">Enable</button>
                                        </form>
                                    @endif
                                @elseif ($user->isDisabled())
                                    <form method="POST" action="{{ route('users.enable', $user) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-secondary btn-sm">Enable</button>
                                    </form>
                                @elseif (! $user->is(auth()->user()))
                                    <x-confirm-dialog :title="'Disable '.$user->name.'?'" :action="route('users.disable', $user)"
                                                      trigger-label="Disable" confirm-label="Disable account">
                                        The account is signed out everywhere and can no longer sign in or act on the gateway. You can re-enable it later.
                                    </x-confirm-dialog>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $users->links() }}
    @endif
</x-layouts.app>
