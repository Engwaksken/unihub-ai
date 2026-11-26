<x-layouts.app title="Users">
    <div class="page-header">
        <div>
            <h1>Users &amp; audit</h1>
            <p class="muted">Staff accounts, roles and project assignments.</p>
        </div>
        <div class="actions-row">
            <a href="{{ route('audit.index') }}" class="btn btn-secondary">Audit log</a>
            <a href="{{ route('users.create') }}" class="btn btn-primary">Invite staff</a>
        </div>
    </div>

    @if ($users->isEmpty())
        <x-empty-state title="No staff yet" icon="users"><p>Invite your first colleague.</p></x-empty-state>
    @else
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
                                    <a href="{{ route('users.edit', $user) }}" class="btn btn-secondary btn-sm">Edit<span class="visually-hidden"> {{ $user->name }}</span></a>
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
                                @if ($user->isDisabled())
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
