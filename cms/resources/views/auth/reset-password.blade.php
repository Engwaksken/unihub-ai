<x-layouts.guest title="Choose a new password">
    <form method="POST" action="{{ route('password.update') }}" class="form">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-form-errors />
        <div class="field">
            <label for="email">E-mail address</label>
            <input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required autocomplete="username">
        </div>
        <div class="field">
            <label for="password">New password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" aria-describedby="password-help">
            <p id="password-help" class="field-help">At least 12 characters, with upper- and lower-case letters and a number.</p>
        </div>
        <div class="field">
            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-block">Reset password</button>
        </div>
    </form>
</x-layouts.guest>
