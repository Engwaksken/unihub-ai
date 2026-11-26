<?php

namespace App\Http\Controllers;

use App\Services\StaffDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class InvitationController extends Controller
{
    public function __construct(private readonly StaffDirectory $staff) {}

    public function show(string $token): Response
    {
        $invitation = $this->staff->findUsableInvitation($token);

        if ($invitation === null) {
            return response()->view('auth.invitation-invalid', [], 410);
        }

        return response()->view('auth.accept-invitation', [
            'token' => $token,
            'email' => $invitation->user->email,
            'name' => $invitation->user->name,
            'expiresAt' => $invitation->expires_at,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse|Response
    {
        if ($this->staff->findUsableInvitation($token) === null) {
            return response()->view('auth.invitation-invalid', [], 410);
        }

        $request->validate([
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        $user = $this->staff->acceptInvitation($token, (string) $request->input('password'));
        if ($user === null) {
            return response()->view('auth.invitation-invalid', [], 410);
        }

        if (Auth::check()) {
            Auth::guard('web')->logout();
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        // The password was just set, so the 2FA enrolment step does not ask
        // for it again.
        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->route('two-factor.setup')
            ->with('status', 'Your password has been set. Now set up two-factor authentication.');
    }
}
