<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StaffDirectory;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Recovery path for an invitation that expired before it was used (for
 * example the bootstrap owner link). Only works for accounts that have never
 * set a password, so it cannot be used to take over an active account.
 */
class ReissueInvitation extends Command
{
    protected $signature = 'cms:reissue-invitation {email}';

    protected $description = 'Issue a new single-use set-password link for an invited user who has not yet set a password';

    public function handle(StaffDirectory $staff): int
    {
        $user = User::query()->where('email', Str::lower((string) $this->argument('email')))->first();

        if ($user === null || $user->hasSetPassword() || $user->isDisabled()) {
            $this->error('No pending (never activated, not disabled) account exists for that e-mail address.');

            return self::FAILURE;
        }

        $token = $staff->issueInvitation($user, null);
        Audit::record('staff.invitation_reissued', actor: null, subjectUser: $user, actorLabel: 'console');

        $this->info('New single-use link (expires in '.(int) config('cms.invitation_ttl_hours', 24).' hours):');
        $this->line(route('invitations.show', ['token' => $token]));

        return self::SUCCESS;
    }
}
