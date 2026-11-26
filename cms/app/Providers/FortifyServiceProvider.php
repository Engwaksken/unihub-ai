<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Disabled accounts and invited users who have not yet set a password
        // can never authenticate. A hash check always runs so response timing
        // does not reveal whether an address exists.
        Fortify::authenticateUsing(function (Request $request) {
            $email = Str::lower((string) $request->input('email'));
            $user = User::query()->where('email', $email)->first();

            $passwordOk = Hash::check((string) $request->input('password'), $user->password ?? self::dummyHash());

            if ($user && $passwordOk && $user->hasSetPassword() && ! $user->isDisabled() && ! $user->isSystem()) {
                return $user;
            }

            return null;
        });

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return [
                Limit::perMinute(5)->by($throttleKey),
                Limit::perMinute(20)->by('ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id'));
        });
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
    }
}
