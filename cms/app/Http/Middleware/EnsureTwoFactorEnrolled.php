<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFA is mandatory for all staff (ADR D12, docs/permissions.md). Any
 * authenticated user without a confirmed TOTP enrolment is sent to the
 * enrolment page before any portal route.
 */
class EnsureTwoFactorEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasConfirmedTwoFactor()) {
            if ($request->expectsJson()) {
                abort(403, 'Two-factor authentication enrolment is required.');
            }

            return redirect()->route('two-factor.setup');
        }

        return $next($request);
    }
}
