<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Mandatory TOTP enrolment. The page drives Fortify's own endpoints:
 *   POST /user/two-factor-authentication            (generate secret)
 *   POST /user/confirmed-two-factor-authentication  (confirm with a code)
 * and shows the recovery codes once, right after confirmation.
 */
class TwoFactorSetupController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        $state = match (true) {
            $user->hasConfirmedTwoFactor() => 'enrolled',
            $user->two_factor_secret !== null => 'pending-confirmation',
            default => 'not-started',
        };

        $setupKey = null;
        $qrSvg = null;
        if ($state === 'pending-confirmation') {
            $setupKey = trim(chunk_split(decrypt($user->two_factor_secret), 4, ' '));
            $qrSvg = $user->twoFactorQrCodeSvg();
        }

        $justConfirmed = session('status') === 'two-factor-authentication-confirmed';

        return view('auth.two-factor-setup', [
            'state' => $state,
            'setupKey' => $setupKey,
            'qrSvg' => $qrSvg,
            'recoveryCodes' => $justConfirmed && $state === 'enrolled' ? $user->recoveryCodes() : [],
        ]);
    }
}
