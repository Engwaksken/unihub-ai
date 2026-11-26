<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Writes authentication activity to the CMS audit trail. Only the submitted
 * e-mail address is kept for failures; passwords and codes are never stored.
 * (Fortify's own events type $event->user as App\Models\User.)
 */
class RecordAuthenticationEvents
{
    public function onLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        Audit::record('auth.login_succeeded', actor: $event->user, subjectUser: $event->user);
    }

    public function onFailed(Failed $event): void
    {
        $email = Str::lower(Str::limit((string) ($event->credentials['email'] ?? ''), 190, ''));
        $user = $event->user instanceof User ? $event->user : User::query()->where('email', $email)->first();

        Audit::record('auth.login_failed', actor: null, subjectUser: $user, metadata: ['email' => $email], actorLabel: $email ?: null);
    }

    public function onLockout(Lockout $event): void
    {
        $email = Str::lower(Str::limit((string) $event->request->input('email', ''), 190, ''));
        Audit::record('auth.login_locked_out', actor: null, metadata: ['email' => $email], actorLabel: $email ?: null);
    }

    public function onLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            Audit::record('auth.logout', actor: $event->user, subjectUser: $event->user);
        }
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            Audit::record('auth.password_reset', actor: $event->user, subjectUser: $event->user);
        }
    }

    public function onTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        Audit::record('auth.two_factor_failed', actor: null, subjectUser: $event->user, actorLabel: $event->user->email);
    }

    public function onTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        Audit::record('auth.two_factor_enrolled', actor: $event->user, subjectUser: $event->user);
    }

    public function onTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        Audit::record('auth.two_factor_disabled', actor: $event->user, subjectUser: $event->user);
    }

    public function onRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        Audit::record('auth.recovery_codes_regenerated', actor: $event->user, subjectUser: $event->user);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            Logout::class => 'onLogout',
            PasswordReset::class => 'onPasswordReset',
            TwoFactorAuthenticationFailed::class => 'onTwoFactorFailed',
            TwoFactorAuthenticationConfirmed::class => 'onTwoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'onTwoFactorDisabled',
            RecoveryCodesGenerated::class => 'onRecoveryCodesGenerated',
        ];
    }
}
