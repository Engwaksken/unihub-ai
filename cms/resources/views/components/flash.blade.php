@php
    $messages = [
        'two-factor-authentication-enabled' => 'Scan the QR code with your authenticator app, then enter the 6-digit code to finish.',
        'two-factor-authentication-confirmed' => 'Two-factor authentication is now active.',
        'two-factor-authentication-disabled' => 'Two-factor authentication was turned off. You must set it up again before using the portal.',
        'recovery-codes-generated' => 'New recovery codes were generated. The old ones no longer work.',
        'password-updated' => 'Your password has been changed.',
    ];
    $status = session('status');
@endphp
@if ($status)
    <div class="alert alert-success" role="status">
        {{ $messages[$status] ?? $status }}
    </div>
@endif
@if ($errors->has('form'))
    <div class="alert alert-error" role="alert">
        @foreach ($errors->get('form') as $message)
            <p>{{ $message }}</p>
        @endforeach
    </div>
@endif
