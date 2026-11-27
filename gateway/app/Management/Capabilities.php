<?php

namespace App\Management;

/**
 * Mirror of docs/permissions.md. Value per role: 'all' (any project), 'assigned'
 * (only projects in the assertion), or absent (denied). The CMS has the same table;
 * tests on both sides pin it.
 */
final class Capabilities
{
    public const ROLES = ['owner', 'ops_admin', 'project_manager', 'auditor'];

    /** Roles that only ever apply to specific projects (via the project_roles claim). */
    public const PROJECT_SCOPED_ROLES = ['project_manager', 'auditor'];

    public const MATRIX = [
        'summary.view' => ['owner' => 'all', 'ops_admin' => 'all', 'project_manager' => 'assigned', 'auditor' => 'assigned'],
        'org.view' => ['owner' => 'all', 'ops_admin' => 'all', 'project_manager' => 'assigned', 'auditor' => 'assigned'],
        'org.manage' => ['owner' => 'all'],
        'project.view' => ['owner' => 'all', 'ops_admin' => 'all', 'project_manager' => 'assigned', 'auditor' => 'assigned'],
        'project.create' => ['owner' => 'all', 'ops_admin' => 'all'],
        'project.edit_meta' => ['owner' => 'all', 'ops_admin' => 'all', 'project_manager' => 'assigned'],
        'project.status' => ['owner' => 'all', 'ops_admin' => 'all'],
        'project.quota' => ['owner' => 'all'],
        'project.allowlist' => ['owner' => 'all', 'ops_admin' => 'all'],
        'key.view' => ['owner' => 'all', 'ops_admin' => 'all', 'project_manager' => 'assigned', 'auditor' => 'assigned'],
        'key.manage' => ['owner' => 'all', 'project_manager' => 'assigned'],
        'model.view' => ['owner' => 'all', 'ops_admin' => 'all', 'project_manager' => 'all', 'auditor' => 'all'],
        'model.manage' => ['owner' => 'all', 'ops_admin' => 'all'],
        'task.view' => ['owner' => 'all', 'ops_admin' => 'all', 'project_manager' => 'all', 'auditor' => 'all'],
        'task.manage' => ['owner' => 'all', 'ops_admin' => 'all'],
        'job.view' => ['owner' => 'all', 'ops_admin' => 'all', 'project_manager' => 'assigned', 'auditor' => 'assigned'],
        'job.operate' => ['owner' => 'all', 'ops_admin' => 'all', 'project_manager' => 'assigned'],
        'job.delete' => ['owner' => 'all', 'ops_admin' => 'all'],
        'usage.view' => ['owner' => 'all', 'ops_admin' => 'all', 'project_manager' => 'assigned', 'auditor' => 'assigned'],
        'health.view' => ['owner' => 'all', 'ops_admin' => 'all', 'auditor' => 'all'],
        'alert.ack' => ['owner' => 'all', 'ops_admin' => 'all'],
        'admission.manage' => ['owner' => 'all', 'ops_admin' => 'all'],
        'audit.view' => ['owner' => 'all', 'ops_admin' => 'all', 'project_manager' => 'assigned', 'auditor' => 'assigned'],
        'settings.view' => ['owner' => 'all', 'ops_admin' => 'all'],
        'settings.policy' => ['owner' => 'all'],
    ];
}
