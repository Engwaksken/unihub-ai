<x-layouts.guest title="Reset your password">
    <p>Enter your staff e-mail address. If an account exists, we will send a link to choose a new password.</p>
    <form method="POST" action="{{ route('password.email') }}" class="form">
        @csrf
        <x-form-errors />
        <div class="field">
            <label for="email">E-mail address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-block">Send reset link</button>
        </div>
        <p class="form-note"><a href="{{ route('login') }}">Back to sign in</a></p>
    </form>
</x-layouts.guest>
