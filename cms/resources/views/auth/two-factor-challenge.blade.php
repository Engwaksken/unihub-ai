<x-layouts.guest title="Two-factor authentication">
    <div x-data="{ recovery: {{ $errors->has('recovery_code') ? 'true' : 'false' }} }">
        <form method="POST" action="{{ url('/two-factor-challenge') }}" class="form">
            @csrf
            <x-form-errors />

            <div x-show="! recovery">
                <p>Enter the 6-digit code from your authenticator app.</p>
                <div class="field">
                    <label for="code">Authentication code</label>
                    <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6"
                           x-bind:disabled="recovery" autofocus>
                </div>
            </div>

            <div x-show="recovery" x-cloak>
                <p>Enter one of your emergency recovery codes. Each code works once.</p>
                <div class="field">
                    <label for="recovery_code">Recovery code</label>
                    <input id="recovery_code" name="recovery_code" type="text" autocomplete="off" x-bind:disabled="! recovery">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-block">Verify</button>
            </div>
            <p class="form-note">
                <button type="button" class="link-button" x-on:click="recovery = ! recovery"
                        x-text="recovery ? 'Use an authentication code instead' : 'Use a recovery code instead'">Use a recovery code instead</button>
            </p>
        </form>
    </div>
</x-layouts.guest>
