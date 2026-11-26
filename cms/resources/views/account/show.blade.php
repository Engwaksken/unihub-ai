<x-layouts.app title="Your account">
    <div class="page-header">
        <h1>Your account</h1>
    </div>

    <section class="card" aria-labelledby="profile-heading">
        <h2 id="profile-heading">Profile</h2>
        <dl class="details">
            <div><dt>Name</dt><dd>{{ $user->name }}</dd></div>
            <div><dt>E-mail</dt><dd>{{ $user->email }}</dd></div>
            <div><dt>Roles</dt><dd>{{ collect($user->roles())->map->label()->join(', ') ?: '—' }}</dd></div>
            <div><dt>Last sign-in</dt><dd><x-time :value="$user->last_login_at" /></dd></div>
        </dl>
        <p class="muted small">Names, e-mail addresses and roles are managed by an owner.</p>
    </section>

    <section class="card" aria-labelledby="mfa-heading">
        <h2 id="mfa-heading">Two-factor authentication</h2>
        <p><x-status-badge status="enrolled" /> Active since <x-time :value="$user->two_factor_confirmed_at" />.</p>
        <div class="actions-row">
            <a class="btn btn-secondary" href="{{ route('account.recovery-codes') }}">View recovery codes</a>
            <x-confirm-dialog title="Generate new recovery codes?" :action="url('/user/two-factor-recovery-codes')"
                              trigger-label="Generate new recovery codes" trigger-class="btn btn-secondary" confirm-label="Generate new codes" confirm-class="btn btn-primary">
                Your current recovery codes will stop working immediately.
            </x-confirm-dialog>
        </div>
    </section>

    <section class="card" aria-labelledby="password-heading">
        <h2 id="password-heading">Change password</h2>
        <form method="POST" action="{{ url('/user/password') }}" class="form form-narrow">
            @csrf
            @method('PUT')
            <x-form-errors bag="updatePassword" />
            <div class="field">
                <label for="current_password">Current password</label>
                <input id="current_password" name="current_password" type="password" required autocomplete="current-password">
            </div>
            <div class="field">
                <label for="password">New password</label>
                <input id="password" name="password" type="password" required autocomplete="new-password" aria-describedby="new-password-help">
                <p id="new-password-help" class="field-help">At least 12 characters, with upper- and lower-case letters and a number.</p>
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Change password</button>
            </div>
        </form>
    </section>
</x-layouts.app>
