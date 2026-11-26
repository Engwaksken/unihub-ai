<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $password_set_at
 * @property bool $is_system
 * @property Carbon|null $disabled_at
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, TwoFactorAuthenticatable;

    protected $fillable = ['name', 'email'];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_set_at' => 'datetime',
            'is_system' => 'boolean',
            'disabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<UserRole, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /** @return HasMany<ProjectMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class);
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        $roles = $this->roleAssignments->map(fn (UserRole $r) => $r->role)->all();
        usort($roles, fn (Role $a, Role $b) => array_search($a, Role::cases(), true) <=> array_search($b, Role::cases(), true));

        return $roles;
    }

    /** @return list<string> */
    public function roleValues(): array
    {
        return array_map(fn (Role $r) => $r->value, $this->roles());
    }

    public function hasRole(Role $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    public function hasMembership(string $projectUuid, ?Role $role = null): bool
    {
        $projectUuid = strtolower($projectUuid);

        return $this->memberships->contains(
            fn (ProjectMembership $m) => strtolower($m->gateway_project_uuid) === $projectUuid
                && ($role === null || $m->role === $role)
        );
    }

    /**
     * Assigned project uuids (all membership roles), as sent in the assertion.
     *
     * @return list<string>
     */
    public function assignedProjectUuids(): array
    {
        return $this->memberships
            ->pluck('gateway_project_uuid')
            ->map(fn ($u) => strtolower($u))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Non-human service account (the alert notifier). It has no password and
     * can never sign in; it only exists to be the `sub` of system assertions.
     */
    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }

    public function hasSetPassword(): bool
    {
        return $this->password_set_at !== null;
    }

    /**
     * MFA counts as enrolled only once the TOTP secret has been confirmed with
     * a valid code (Fortify "confirm" mode).
     */
    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * @param  iterable<Role>  $roles
     */
    public function syncRoles(iterable $roles): void
    {
        $values = [];
        foreach ($roles as $role) {
            $values[$role->value] = $role;
        }

        DB::transaction(function () use ($values) {
            $this->roleAssignments()->whereNotIn('role', array_keys($values))->delete();
            $existing = $this->roleAssignments()->pluck('role')->map(fn ($r) => $r instanceof Role ? $r->value : $r)->all();
            foreach ($values as $value => $role) {
                if (! in_array($value, $existing, true)) {
                    $this->roleAssignments()->create(['role' => $role]);
                }
            }
        });

        $this->unsetRelation('roleAssignments');
    }

    /**
     * Replace all memberships with the given map of project uuid => role.
     *
     * @param  array<string, Role>  $memberships
     */
    public function syncMemberships(array $memberships): void
    {
        DB::transaction(function () use ($memberships) {
            $normalised = [];
            foreach ($memberships as $uuid => $role) {
                $normalised[strtolower($uuid)] = $role;
            }

            $this->memberships()->whereNotIn('gateway_project_uuid', array_keys($normalised) ?: ['-'])->delete();

            foreach ($normalised as $uuid => $role) {
                $this->memberships()->updateOrCreate(
                    ['gateway_project_uuid' => $uuid],
                    ['role' => $role],
                );
            }
        });

        $this->unsetRelation('memberships');
    }

    public function activeSessionCount(): int
    {
        return DB::table(config('session.table', 'sessions'))->where('user_id', $this->getKey())->count();
    }

    public static function ownerExists(): bool
    {
        return UserRole::query()->where('role', Role::Owner->value)->exists();
    }
}
