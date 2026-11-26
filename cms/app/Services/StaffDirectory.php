<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\StaffInvitation;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Staff lifecycle: invitations, role/membership changes, disabling and
 * session revocation. Every change is written to the CMS audit trail.
 */
class StaffDirectory
{
    /**
     * Create an invited staff account and return the plain invitation token.
     * The account cannot sign in until the invitee sets a password.
     *
     * @param  list<Role>  $roles
     * @param  array<string, Role>  $memberships  gateway project uuid => role
     * @return array{0: User, 1: string}
     */
    public function invite(string $email, string $name, array $roles, array $memberships, ?User $invitedBy, bool $notify = true): array
    {
        if ($roles === []) {
            throw new InvalidArgumentException('At least one role is required.');
        }
        $this->assertMembershipsMatchRoles($roles, $memberships);

        [$user, $token] = DB::transaction(function () use ($email, $name, $roles, $memberships, $invitedBy) {
            $user = new User(['name' => $name, 'email' => Str::lower($email)]);
            // Unusable until the invitation is accepted: a random hash nobody knows.
            $user->password = Hash::make(Str::random(64));
            $user->save();

            $user->syncRoles($roles);
            $user->syncMemberships($memberships);

            $token = $this->issueInvitation($user, $invitedBy);

            return [$user, $token];
        });

        Audit::record('staff.invited', actor: $invitedBy, subjectUser: $user, metadata: [
            'email' => $user->email,
            'roles' => array_map(fn (Role $r) => $r->value, $roles),
            'memberships' => $this->membershipMetadata($memberships),
        ]);

        foreach ($memberships as $uuid => $role) {
            Audit::record('staff.membership_added', actor: $invitedBy, subjectUser: $user, metadata: ['role' => $role->value], projectUuid: $uuid);
        }

        if ($notify) {
            $user->notify(new StaffInvitation($token));
        }

        return [$user, $token];
    }

    /**
     * Revoke any outstanding invitation and issue a new single-use token.
     */
    public function issueInvitation(User $user, ?User $invitedBy): string
    {
        $user->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $token = Str::random(48);

        Invitation::query()->create([
            'user_id' => $user->getKey(),
            'invited_by' => $invitedBy?->getKey(),
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addHours((int) config('cms.invitation_ttl_hours', 24)),
        ]);

        return $token;
    }

    public function resendInvitation(User $user, User $actor): void
    {
        if ($user->isSystem()) {
            throw new InvalidArgumentException('System accounts cannot be invited.');
        }
        if ($user->hasSetPassword()) {
            throw new InvalidArgumentException('This user has already accepted their invitation.');
        }

        $token = $this->issueInvitation($user, $actor);
        Audit::record('staff.invitation_reissued', actor: $actor, subjectUser: $user);
        $user->notify(new StaffInvitation($token));
    }

    public function findUsableInvitation(string $plainToken): ?Invitation
    {
        if ($plainToken === '' || strlen($plainToken) > 128) {
            return null;
        }

        $invitation = Invitation::query()->with('user')->where('token_hash', Invitation::hashToken($plainToken))->first();

        return $invitation && $invitation->isUsable() && $invitation->user && ! $invitation->user->isDisabled() && ! $invitation->user->isSystem() ? $invitation : null;
    }

    /**
     * Accept an invitation: set the password and mark the token used. Returns
     * null if the token is unknown, expired, revoked or already used.
     */
    public function acceptInvitation(string $plainToken, string $password): ?User
    {
        return DB::transaction(function () use ($plainToken, $password) {
            $invitation = $this->findUsableInvitation($plainToken);
            if ($invitation === null) {
                return null;
            }

            // Single use: only one concurrent request can flip accepted_at.
            $claimed = Invitation::query()
                ->whereKey($invitation->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['accepted_at' => now(), 'updated_at' => now()]);
            if ($claimed !== 1) {
                return null;
            }

            $user = $invitation->user;
            $user->forceFill([
                'password' => Hash::make($password),
                'password_set_at' => now(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            Audit::record('staff.invitation_accepted', actor: $user, subjectUser: $user);

            return $user;
        });
    }

    /**
     * @param  list<Role>  $roles
     * @param  array<string, Role>  $memberships
     */
    public function updateAccess(User $user, array $roles, array $memberships, User $actor): void
    {
        if ($roles === []) {
            throw new InvalidArgumentException('At least one role is required.');
        }
        $this->assertMembershipsMatchRoles($roles, $memberships);

        $beforeRoles = $user->roleValues();
        $beforeMemberships = $user->memberships->mapWithKeys(fn ($m) => [strtolower($m->gateway_project_uuid) => $m->role->value])->all();

        if (in_array(Role::Owner->value, $beforeRoles, true) && ! in_array(Role::Owner, $roles, true) && $this->ownerCount() <= 1) {
            throw new InvalidArgumentException('The last owner cannot lose the owner role.');
        }

        DB::transaction(function () use ($user, $roles, $memberships) {
            $user->syncRoles($roles);
            $user->syncMemberships($memberships);
        });

        $afterRoles = array_map(fn (Role $r) => $r->value, $roles);
        sort($afterRoles);
        $sortedBefore = $beforeRoles;
        sort($sortedBefore);
        if ($sortedBefore !== $afterRoles) {
            Audit::record('staff.roles_changed', actor: $actor, subjectUser: $user, metadata: ['before' => $sortedBefore, 'after' => $afterRoles]);
        }

        $after = [];
        foreach ($memberships as $uuid => $role) {
            $after[strtolower($uuid)] = $role->value;
        }

        foreach ($after as $uuid => $role) {
            if (! isset($beforeMemberships[$uuid])) {
                Audit::record('staff.membership_added', actor: $actor, subjectUser: $user, metadata: ['role' => $role], projectUuid: $uuid);
            } elseif ($beforeMemberships[$uuid] !== $role) {
                Audit::record('staff.membership_changed', actor: $actor, subjectUser: $user, metadata: ['before' => $beforeMemberships[$uuid], 'after' => $role], projectUuid: $uuid);
            }
        }
        foreach ($beforeMemberships as $uuid => $role) {
            if (! isset($after[$uuid])) {
                Audit::record('staff.membership_removed', actor: $actor, subjectUser: $user, metadata: ['role' => $role], projectUuid: $uuid);
            }
        }

        // Role changes take effect on the next request; assertions are signed
        // per request from the current database state.
        $user->refresh();
    }

    public function disable(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw new InvalidArgumentException('You cannot disable your own account.');
        }
        if ($user->hasRole(Role::Owner) && $this->ownerCount(activeOnly: true) <= 1) {
            throw new InvalidArgumentException('The last active owner cannot be disabled.');
        }

        $user->forceFill(['disabled_at' => now()])->save();
        $revoked = $this->deleteSessions($user);
        $user->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);

        Audit::record('staff.disabled', actor: $actor, subjectUser: $user, metadata: ['sessions_revoked' => $revoked]);
    }

    public function enable(User $user, User $actor): void
    {
        $user->forceFill(['disabled_at' => null])->save();
        Audit::record('staff.enabled', actor: $actor, subjectUser: $user);
    }

    /**
     * Delete every database session of the user (forces sign-in everywhere).
     */
    public function revokeSessions(User $user, User $actor): int
    {
        $count = $this->deleteSessions($user);
        Audit::record('staff.sessions_revoked', actor: $actor, subjectUser: $user, metadata: ['sessions_revoked' => $count]);

        return $count;
    }

    private function deleteSessions(User $user): int
    {
        $count = DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        return $count;
    }

    private function ownerCount(bool $activeOnly = false): int
    {
        return User::query()
            ->whereHas('roleAssignments', fn ($q) => $q->where('role', Role::Owner->value))
            ->when($activeOnly, fn ($q) => $q->whereNull('disabled_at'))
            ->count();
    }

    /**
     * Each membership must use a project-scoped role the user actually holds.
     *
     * @param  list<Role>  $roles
     * @param  array<string, Role>  $memberships
     */
    private function assertMembershipsMatchRoles(array $roles, array $memberships): void
    {
        foreach ($memberships as $uuid => $role) {
            if (! Str::isUuid((string) $uuid)) {
                throw new InvalidArgumentException('Project memberships must reference gateway project UUIDs.');
            }
            if (! $role->isProjectScoped() || ! in_array($role, $roles, true)) {
                throw new InvalidArgumentException("A {$role->label()} membership requires the {$role->label()} role.");
            }
        }
    }

    /**
     * @param  array<string, Role>  $memberships
     * @return array<string, string>
     */
    private function membershipMetadata(array $memberships): array
    {
        $out = [];
        foreach ($memberships as $uuid => $role) {
            $out[strtolower($uuid)] = $role->value;
        }

        return $out;
    }
}
