<x-layouts.guest title="Sign in">
    <form method="POST" action="{{ route('login') }}" class="form" x-data="{ busy: false }" x-on:submit="busy = true">
        @csrf
        <x-form-errors />

        <div class="field">
            <label for="email">E-mail address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email') <p id="email-error" class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-block" x-bind:disabled="busy">
                <span x-show="! busy">Sign in</span>
                <span x-show="busy" x-cloak>Signing in…</span>
            </button>
        </div>
        <p class="form-note"><a href="{{ route('password.request') }}">Forgot your password?</a></p>
        <p class="form-note muted">Staff accounts are created by invitation only.</p>
    </form>
</x-layouts.guest>
