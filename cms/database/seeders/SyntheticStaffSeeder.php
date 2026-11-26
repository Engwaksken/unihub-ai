<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Synthetic staff for local development and staging. All addresses use the
 * reserved example.test domain. No real people or data.
 *
 * The project UUIDs below are placeholders for the gateway's synthetic
 * fixtures (P1-8). Set SEED_PROJECT_UUIDS (comma-separated) to the UUIDs the
 * gateway seeder produced so the memberships point at real staging projects.
 *
 * Accounts are created without MFA, so each one is sent to TOTP enrolment on
 * first sign-in. A random password is generated and printed once.
 */
class SyntheticStaffSeeder extends Seeder
{
    private const DEFAULT_PROJECTS = [
        '0a3c2f1e-5b7d-4c9a-8e21-3f6b9d0c1a01',
        '0a3c2f1e-5b7d-4c9a-8e21-3f6b9d0c1a02',
    ];

    public function run(): void
    {
        $projects = array_values(array_filter(array_map('trim', explode(',', (string) config('cms.seed_project_uuids', '')))));
        $projects = array_values(array_filter($projects, fn ($u) => Str::isUuid($u))) ?: self::DEFAULT_PROJECTS;

        $password = Str::password(16);
        $hash = Hash::make($password);

        $staff = [
            ['owner@example.test', 'Olivia Owner (synthetic)', [Role::Owner], []],
            ['ops@example.test', 'Oscar Ops (synthetic)', [Role::OpsAdmin], []],
            ['pm@example.test', 'Priya Manager (synthetic)', [Role::ProjectManager], [$projects[0] => Role::ProjectManager]],
            ['auditor@example.test', 'Amos Auditor (synthetic)', [Role::Auditor], array_fill_keys($projects, Role::Auditor)],
        ];

        foreach ($staff as [$email, $name, $roles, $memberships]) {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->name = $name;
            $user->forceFill([
                'password' => $hash,
                'password_set_at' => now(),
                'email_verified_at' => now(),
            ])->save();
            $user->syncRoles($roles);
            $user->syncMemberships($memberships);
        }

        $this->command?->info('Synthetic staff seeded: owner@, ops@, pm@, auditor@example.test');
        $this->command?->warn("Shared password for these local accounts: {$password}");
    }
}
