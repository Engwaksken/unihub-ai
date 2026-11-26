<x-layouts.guest title="Set up two-factor authentication" :wide="true">
    @if ($state === 'not-started')
        <p>Two-factor authentication is required for every staff account. You will need an authenticator app such as Google Authenticator, Microsoft Authenticator or 1Password.</p>
        <form method="POST" action="{{ url('/user/two-factor-authentication') }}" class="form">
            @csrf
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Start setup</button>
            </div>
        </form>
    @elseif ($state === 'pending-confirmation')
        <ol class="steps">
            <li>
                <p>Scan this QR code with your authenticator app.</p>
                {{-- SVG generated locally by Fortify from our own TOTP secret; it contains no user- or gateway-supplied markup. --}}
                <div class="qr">{!! $qrSvg !!}</div>
                <p class="small">Can’t scan it? Enter this setup key instead:</p>
                <p><code class="setup-key">{{ $setupKey }}</code></p>
            </li>
            <li>
                <form method="POST" action="{{ url('/user/confirmed-two-factor-authentication') }}" class="form">
                    @csrf
                    <x-form-errors bag="confirmTwoFactorAuthentication" />
                    <div class="field">
                        <label for="code">Enter the 6-digit code shown in the app</label>
                        <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required autofocus>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Confirm and activate</button>
                    </div>
                </form>
            </li>
        </ol>
    @else
        @if ($recoveryCodes)
            <div class="alert alert-warning" role="status">
                <p class="alert-title">Save your recovery codes now</p>
                <p>Store these codes somewhere safe (for example a password manager). Each one lets you sign in once if you lose your phone. They will not be shown again on this page.</p>
            </div>
            <ul class="recovery-codes">
                @foreach ($recoveryCodes as $code)
                    <li><code>{{ $code }}</code></li>
                @endforeach
            </ul>
        @else
            <p>Two-factor authentication is active on your account.</p>
        @endif
        <p><a class="btn btn-primary" href="{{ route('home') }}">Continue to the portal</a></p>
    @endif

    <form method="POST" action="{{ route('logout') }}" class="form-note">
        @csrf
        <button type="submit" class="link-button">Sign out</button>
    </form>
</x-layouts.guest>
