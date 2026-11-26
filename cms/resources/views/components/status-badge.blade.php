@props(['status'])
@php
    $variant = match ($status) {
        'active', 'enrolled', 'open-admission', 'resolved' => 'success',
        'disabled', 'invited', 'mfa-pending', 'expired', 'acknowledged', 'warning' => 'warning',
        'archived', 'revoked', 'system' => 'muted',
        'open', 'critical', 'paused' => 'error',
        default => 'neutral',
    };
    $label = match ($status) {
        'mfa-pending' => 'MFA pending',
        default => \Illuminate\Support\Str::headline((string) $status),
    };
@endphp
<span {{ $attributes->class(['badge', 'badge-'.$variant]) }}>{{ $label }}</span>
