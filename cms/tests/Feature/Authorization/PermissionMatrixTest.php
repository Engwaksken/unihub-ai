<?php

namespace Tests\Feature\Authorization;

use App\Authorization\Permissions;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * An independent transcription of docs/permissions.md. If Permissions::MATRIX
 * drifts from the document, these tests fail.
 *
 * A = All, S = Assigned (own memberships only), - = denied.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const ASSIGNED_PROJECT = '6f1d8a8e-1111-4c4c-9a9a-00000000aaaa';

    private const OTHER_PROJECT = '6f1d8a8e-1111-4c4c-9a9a-00000000bbbb';

    /** capability => [owner, ops_admin, project_manager, auditor] */
    private const EXPECTED = [
        'overview.view' => ['A', 'A', 'S', 'S'],
        'organization.view' => ['A', 'A', '-', '-'],
        'organization.manage' => ['A', '-', '-', '-'],
        'project.view' => ['A', 'A', 'S', 'S'],
        'project.create' => ['A', 'A', '-', '-'],
        'project.edit_metadata' => ['A', 'A', 'S', '-'],
        'project.change_status' => ['A', 'A', '-', '-'],
        'project.change_quotas' => ['A', '-', '-', '-'],
        'project.change_allowlist' => ['A', 'A', '-', '-'],
        'key.manage' => ['A', '-', 'S', '-'],
        'key.view' => ['A', 'A', 'S', 'S'],
        'model.view' => ['A', 'A', 'A', 'A'],
        'model.enable' => ['A', 'A', '-', '-'],
        'task.view' => ['A', 'A', 'A', 'A'],
        'task.manage' => ['A', 'A', '-', '-'],
        'job.view' => ['A', 'A', 'S', 'S'],
        'job.manage' => ['A', 'A', 'S', '-'],
        'job.delete' => ['A', 'A', '-', '-'],
        'usage.view' => ['A', 'A', 'S', 'S'],
        'health.view' => ['A', 'A', '-', 'A'],
        'alert.acknowledge' => ['A', 'A', '-', '-'],
        'admission.manage' => ['A', 'A', '-', '-'],
        'staff.manage' => ['A', '-', '-', '-'],
        'audit.view' => ['A', 'A', 'S', 'S'],
        'settings.view' => ['A', 'A', '-', '-'],
        'settings.policy' => ['A', '-', '-', '-'],
        'settings.cms' => ['A', 'A', '-', '-'],
        'provider.approve' => ['A', '-', '-', '-'],
    ];

    private const ROLES = [Role::Owner, Role::OpsAdmin, Role::ProjectManager, Role::Auditor];

    public function test_matrix_covers_exactly_the_expected_capabilities(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(self::EXPECTED), Permissions::capabilities());
    }

    /** @return iterable<string, array{string, Role, string}> */
    public static function cells(): iterable
    {
        foreach (self::EXPECTED as $capability => $row) {
            foreach (self::ROLES as $i => $role) {
                yield "{$capability} / {$role->value}" => [$capability, $role, $row[$i]];
            }
        }
    }

    #[DataProvider('cells')]
    public function test_cell(string $capability, Role $role, string $expected): void
    {
        $user = User::factory()->withTwoFactor()->withRoles($role)
            ->withMemberships($role->isProjectScoped() ? [self::ASSIGNED_PROJECT => $role] : [])
            ->create();

        $gate = Gate::forUser($user);

        switch ($expected) {
            case 'A':
                $this->assertTrue($gate->allows($capability), 'general');
                $this->assertTrue($gate->allows($capability, self::ASSIGNED_PROJECT), 'assigned project');
                $this->assertTrue($gate->allows($capability, self::OTHER_PROJECT), 'any project');
                $this->assertNull(Permissions::projectScope($user, $capability));
                break;
            case 'S':
                $this->assertTrue($gate->allows($capability), 'general (list) access');
                $this->assertTrue($gate->allows($capability, self::ASSIGNED_PROJECT), 'assigned project');
                $this->assertFalse($gate->allows($capability, self::OTHER_PROJECT), 'unassigned project must be denied');
                $this->assertSame([self::ASSIGNED_PROJECT], Permissions::projectScope($user, $capability));
                $this->assertTrue(Permissions::isProjectScoped($capability));
                break;
            default:
                $this->assertFalse($gate->allows($capability), 'general');
                $this->assertFalse($gate->allows($capability, self::ASSIGNED_PROJECT), 'assigned project');
                $this->assertSame([], Permissions::projectScope($user, $capability));
        }
    }

    public function test_membership_counts_only_for_the_role_it_was_granted_with(): void
    {
        // Project manager of A, auditor of B: may edit A's metadata, not B's.
        $user = User::factory()->withTwoFactor()
            ->withRoles([Role::ProjectManager, Role::Auditor])
            ->withMemberships([self::ASSIGNED_PROJECT => Role::ProjectManager, self::OTHER_PROJECT => Role::Auditor])
            ->create();

        $this->assertTrue(Gate::forUser($user)->allows('project.edit_metadata', self::ASSIGNED_PROJECT));
        $this->assertFalse(Gate::forUser($user)->allows('project.edit_metadata', self::OTHER_PROJECT));
        $this->assertTrue(Gate::forUser($user)->allows('project.view', self::OTHER_PROJECT));
    }

    public function test_disabled_users_hold_no_capability(): void
    {
        $user = User::factory()->owner()->disabled()->create();

        foreach (Permissions::capabilities() as $capability) {
            $this->assertFalse(Gate::forUser($user)->allows($capability), $capability);
        }
    }

    public function test_unknown_capability_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Permissions::allows(User::factory()->owner()->create(), 'project.delete_everything');
    }
}
