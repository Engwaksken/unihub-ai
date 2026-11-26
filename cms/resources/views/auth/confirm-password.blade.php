<x-layouts.guest title="Confirm your password">
    <p>For your security, please confirm your password to continue.</p>
    <form method="POST" action="{{ route('password.confirm.store') }}" class="form">
        @csrf
        <x-form-errors />
        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autofocus autocomplete="current-password">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-block">Confirm</button>
        </div>
    </form>
</x-layouts.guest>
