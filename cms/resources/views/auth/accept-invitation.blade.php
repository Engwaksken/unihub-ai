<x-layouts.guest title="Set your password">
    <p>Welcome, {{ $name }}. Choose a password for <strong>{{ $email }}</strong>. Next you will set up an authenticator app; two-factor authentication is required for all staff.</p>
    <p class="muted small">This link works once and expires <x-time :value="$expiresAt" /> ({{ \App\Support\DisplayTime::zone() }}).</p>
    <form method="POST" action="{{ route('invitations.accept', ['token' => $token]) }}" class="form">
        @csrf
        <x-form-errors />
        <input type="text" name="email" value="{{ $email }}" autocomplete="username" hidden readonly>
        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autofocus autocomplete="new-password" aria-describedby="password-help">
            <p id="password-help" class="field-help">At least 12 characters, with upper- and lower-case letters and a number.</p>
        </div>
        <div class="field">
            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-block">Set password and continue</button>
        </div>
    </form>
</x-layouts.guest>
