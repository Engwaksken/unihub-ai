<?php

namespace App\Authorization;

use App\Enums\Role;
use App\Models\User;
use InvalidArgumentException;

/**
 * The single source of truth for staff authorization in the CMS. It transcribes
 * docs/permissions.md and is mirrored by the gateway management API, which
 * re-enforces the same matrix using the assertion's `roles`/`projects` claims.
 *
 * For each capability:
 *  - `roles` maps a role to its scope: ALL (every organization/project) or
 *    ASSIGNED (only projects in the user's project_memberships held *with that
 *    role*). Roles that are absent are denied.
 *  - `project_scoped` says whether the capability acts on a project resource,
 *    i.e. whether ASSIGNED scope is meaningful and a project uuid should be
 *    passed when checking a single resource.
 *
 * Every capability is registered as a Laravel Gate ability with the same name
 * (see AppServiceProvider), e.g. Gate::allows('project.edit_metadata', $uuid).
 */
final class Permissions
{
    public const ALL = 'all';

    public const ASSIGNED = 'assigned';

    private const O = 'owner';

    private const OPS = 'ops_admin';

    private const PM = 'project_manager';

    private const AUD = 'auditor';

    /**
     * @var array<string, array{label: string, project_scoped: bool, roles: array<string, string>}>
     */
    public const MATRIX = [
        'overview.view' => [
            'label' => 'View overview / summary',
            'project_scoped' => true,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL, self::PM => self::ASSIGNED, self::AUD => self::ASSIGNED],
        ],
        // CMS addition: docs/permissions.md has no explicit "view organizations"
        // row. Organizations are listed to the roles that can create projects.
        'organization.view' => [
            'label' => 'View organizations',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL],
        ],
        'organization.manage' => [
            'label' => 'Create / rename / archive organization',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL],
        ],
        // Implied by the matrix: every role sees the projects in its scope.
        'project.view' => [
            'label' => 'View projects',
            'project_scoped' => true,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL, self::PM => self::ASSIGNED, self::AUD => self::ASSIGNED],
        ],
        'project.create' => [
            'label' => 'Create project',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL],
        ],
        'project.edit_metadata' => [
            'label' => 'Edit project metadata (name, contact, environment)',
            'project_scoped' => true,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL, self::PM => self::ASSIGNED],
        ],
        'project.change_status' => [
            'label' => 'Change project status (enable/disable/archive)',
            'project_scoped' => true,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL],
        ],
        'project.change_quotas' => [
            'label' => 'Change quotas / queue limits',
            'project_scoped' => true,
            'roles' => [self::O => self::ALL],
        ],
        'project.change_allowlist' => [
            'label' => 'Change task/model allowlist for a project',
            'project_scoped' => true,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL],
        ],
        'key.manage' => [
            'label' => 'Issue / rotate / revoke API key',
            'project_scoped' => true,
            'roles' => [self::O => self::ALL, self::PM => self::ASSIGNED],
        ],
        'key.view' => [
            'label' => 'View key metadata',
            'project_scoped' => true,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL, self::PM => self::ASSIGNED, self::AUD => self::ASSIGNED],
        ],
        'model.view' => [
            'label' => 'View models',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL, self::PM => self::ALL, self::AUD => self::ALL],
        ],
        'model.enable' => [
            'label' => 'Enable approved model tag',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL],
        ],
        // Mirrors gateway task.view: everyone may read task definitions.
        'task.view' => [
            'label' => 'View tasks and versions',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL, self::PM => self::ALL, self::AUD => self::ALL],
        ],
        'task.manage' => [
            'label' => 'Create task / publish new task version',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL],
        ],
        'job.view' => [
            'label' => 'View job metadata',
            'project_scoped' => true,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL, self::PM => self::ASSIGNED, self::AUD => self::ASSIGNED],
        ],
        'job.manage' => [
            'label' => 'Cancel queued job / retry eligible failed job',
            'project_scoped' => true,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL, self::PM => self::ASSIGNED],
        ],
        'usage.view' => [
            'label' => 'View usage & reports; CSV export',
            'project_scoped' => true,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL, self::PM => self::ASSIGNED, self::AUD => self::ASSIGNED],
        ],
        'health.view' => [
            'label' => 'View health',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL, self::AUD => self::ALL],
        ],
        'alert.acknowledge' => [
            'label' => 'Acknowledge alerts',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL],
        ],
        'admission.manage' => [
            'label' => 'Pause/resume admission (incident)',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL],
        ],
        'staff.manage' => [
            'label' => 'Invite staff; assign roles/memberships; revoke sessions',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL],
        ],
        'audit.view' => [
            'label' => 'View / export audit',
            'project_scoped' => true,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL, self::PM => self::ASSIGNED, self::AUD => self::ASSIGNED],
        ],
        // Mirrors gateway settings.view: reading gateway policy settings.
        'settings.view' => [
            'label' => 'View gateway policy settings',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL],
        ],
        'settings.policy' => [
            'label' => 'Edit settings: limits, retention, providers',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL],
        ],
        'settings.cms' => [
            'label' => 'Edit settings: branding, timezone, notification recipients',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL, self::OPS => self::ALL],
        ],
        'provider.approve' => [
            'label' => 'Approve external provider',
            'project_scoped' => false,
            'roles' => [self::O => self::ALL],
        ],
    ];

    /** @return list<string> */
    public static function capabilities(): array
    {
        return array_keys(self::MATRIX);
    }

    public static function isProjectScoped(string $capability): bool
    {
        return self::definition($capability)['project_scoped'];
    }

    /**
     * The scope a single role has for a capability: ALL, ASSIGNED or null (denied).
     */
    public static function scopeForRole(string $capability, Role $role): ?string
    {
        return self::definition($capability)['roles'][$role->value] ?? null;
    }

    /**
     * Whether the user holds the capability.
     *
     * With $projectUuid = null this answers "may the user use this capability
     * at all" (list pages; results must then be filtered with projectScope()).
     * With a project uuid it answers for that single project.
     */
    public static function allows(User $user, string $capability, ?string $projectUuid = null): bool
    {
        $definition = self::definition($capability);

        if ($user->isDisabled()) {
            return false;
        }

        foreach ($user->roles() as $role) {
            $scope = $definition['roles'][$role->value] ?? null;

            if ($scope === self::ALL) {
                return true;
            }

            if ($scope === self::ASSIGNED) {
                if ($projectUuid === null) {
                    return true;
                }
                if ($user->hasMembership($projectUuid, $role)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The projects the user may use the capability on: null means all
     * projects, otherwise the list of assigned project uuids ([] = none).
     *
     * @return list<string>|null
     */
    public static function projectScope(User $user, string $capability): ?array
    {
        $definition = self::definition($capability);

        if ($user->isDisabled()) {
            return [];
        }

        $uuids = [];
        foreach ($user->roles() as $role) {
            $scope = $definition['roles'][$role->value] ?? null;

            if ($scope === self::ALL) {
                return null;
            }

            if ($scope === self::ASSIGNED) {
                foreach ($user->memberships as $membership) {
                    if ($membership->role === $role) {
                        $uuids[] = strtolower($membership->gateway_project_uuid);
                    }
                }
            }
        }

        $uuids = array_values(array_unique($uuids));
        sort($uuids);

        return $uuids;
    }

    /**
     * @return array{label: string, project_scoped: bool, roles: array<string, string>}
     */
    private static function definition(string $capability): array
    {
        if (! isset(self::MATRIX[$capability])) {
            throw new InvalidArgumentException("Unknown capability [{$capability}].");
        }

        return self::MATRIX[$capability];
    }
}
