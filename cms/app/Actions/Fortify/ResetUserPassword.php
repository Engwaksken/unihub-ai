<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
            'password_set_at' => now(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        // A reset proves control of the mailbox, so any pending invitation
        // link for this account is no longer needed.
        $user->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
