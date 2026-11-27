<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates (idempotently) the non-human account the scheduler uses to call the
 * gateway. It holds only the `auditor` role with no project memberships:
 * auditors have health.view for ALL projects (docs/permissions.md), which is
 * exactly what GET /alerts needs, and no write capability anywhere.
 *
 * The account can never sign in (no password is ever set; Fortify rejects
 * system accounts) and owners cannot invite it.
 */
class CreateSystemUser extends Command
{
    public const EMAIL = 'alert-notifier@system.invalid';

    protected $signature = 'cms:system-user';

    protected $description = 'Create the system actor used by scheduled gateway calls (alert notifier)';

    public function handle(): int
    {
        $user = User::query()->where('email', self::EMAIL)->first();
        $created = false;

        if ($user === null) {
            $user = new User(['name' => 'CMS alert notifier (system)', 'email' => self::EMAIL]);
            $user->password = Hash::make(Str::random(64)); // never disclosed, never usable
            $user->forceFill(['is_system' => true, 'password_set_at' => null])->save();
            $user->syncRoles([Role::Auditor]);
            $user->syncMemberships([]);
            $created = true;
            Audit::record('staff.system_user_created', actor: null, subjectUser: $user, actorLabel: 'console');
        } elseif (! $user->isSystem()) {
            $this->error('A non-system account already uses '.self::EMAIL.'. Resolve this manually.');

            return self::FAILURE;
        }

        $reenabled = false;
        if ($user->isDisabled()) {
            // Older releases let an owner disable it from the Users page; that silently stops
            // the alert notifier and the gateway check, so this setup command puts it back.
            $user->forceFill(['disabled_at' => null])->save();
            Audit::record('staff.enabled', actor: null, subjectUser: $user, actorLabel: 'console', metadata: ['reason' => 'cms:system-user']);
            $reenabled = true;
        }

        $this->info($created ? 'System actor created.' : ($reenabled ? 'System actor re-enabled (it had been disabled).' : 'System actor already exists.'));
        $this->table(['Field', 'Value'], [
            ['sub (user uuid)', $user->id],
            ['name', $user->name],
            ['roles', implode(', ', $user->roleValues())],
            ['project_roles', '{} (none)'],
            ['disabled', $user->isDisabled() ? 'yes — the notifier will not run' : 'no'],
        ]);
        $this->line('The notifier signs assertions with sub = this uuid, roles = ["auditor"], projects = [], project_roles = {}, mfa = true.');

        return self::SUCCESS;
    }

    public static function find(): ?User
    {
        return User::query()->where('email', self::EMAIL)->where('is_system', true)->first();
    }
}
