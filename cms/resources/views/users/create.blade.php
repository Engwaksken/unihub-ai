<x-layouts.app title="Invite staff">
    <div class="page-header">
        <div>
            <p class="breadcrumb"><a href="{{ route('users.index') }}">Users &amp; audit</a></p>
            <h1>Invite staff</h1>
            <p class="muted">The invitee receives a single-use link (valid {{ (int) config('cms.invitation_ttl_hours', 24) }} hours) to set a password, then must set up two-factor authentication.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('users.store') }}" class="card form">
        @csrf
        <x-form-errors />
        <div class="form-grid">
            <div class="field">
                <label for="name">Full name</label>
                <input id="name" name="name" type="text" maxlength="120" required value="{{ old('name') }}">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="email">E-mail address</label>
                <input id="email" name="email" type="email" maxlength="190" required value="{{ old('email') }}">
                @error('email') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        @include('users._access', [
            'selectedRoles' => old('roles', []),
            'currentMemberships' => [],
        ])

        <div class="form-actions">
            <a href="{{ route('users.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Send invitation</button>
        </div>
    </form>
</x-layouts.app>
