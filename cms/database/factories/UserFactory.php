<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\RecoveryCode;

/**
 * Synthetic staff accounts for tests and local seeding.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->userName().'@example.test',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Correct-Horse-9'),
            'password_set_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Confirmed TOTP enrolment (MFA complete).
     */
    public function withTwoFactor(?string $secret = null): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => encrypt($secret ?? app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(Collection::times(8, fn () => RecoveryCode::generate())->all())),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function invited(): static
    {
        return $this->state(fn () => [
            'password' => Hash::make(Str::random(64)),
            'password_set_at' => null,
            'email_verified_at' => null,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['disabled_at' => now()]);
    }

    /**
     * @param  Role|list<Role>  $roles
     */
    public function withRoles(Role|array $roles): static
    {
        $roles = is_array($roles) ? $roles : [$roles];

        return $this->afterCreating(fn (User $user) => $user->syncRoles($roles));
    }

    /**
     * @param  array<string, Role>  $memberships  project uuid => role
     */
    public function withMemberships(array $memberships): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncMemberships($memberships));
    }

    public function owner(): static
    {
        return $this->withTwoFactor()->withRoles(Role::Owner);
    }

    public function opsAdmin(): static
    {
        return $this->withTwoFactor()->withRoles(Role::OpsAdmin);
    }

    /**
     * @param  list<string>  $projectUuids
     */
    public function projectManager(array $projectUuids = []): static
    {
        return $this->withTwoFactor()
            ->withRoles(Role::ProjectManager)
            ->withMemberships(array_fill_keys($projectUuids, Role::ProjectManager));
    }

    /**
     * @param  list<string>  $projectUuids
     */
    public function auditor(array $projectUuids = []): static
    {
        return $this->withTwoFactor()
            ->withRoles(Role::Auditor)
            ->withMemberships(array_fill_keys($projectUuids, Role::Auditor));
    }
}
