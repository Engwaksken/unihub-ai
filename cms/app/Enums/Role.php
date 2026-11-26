<?php

namespace App\Enums;

/**
 * Staff roles. The string values are part of the wire contract: they are sent
 * in the actor assertion `roles` claim (docs/api/management-auth.md).
 */
enum Role: string
{
    case Owner = 'owner';
    case OpsAdmin = 'ops_admin';
    case ProjectManager = 'project_manager';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::OpsAdmin => 'Ops admin',
            self::ProjectManager => 'Project manager',
            self::Auditor => 'Auditor',
        };
    }

    /**
     * Roles whose access is limited to the projects in project_memberships.
     */
    public function isProjectScoped(): bool
    {
        return $this === self::ProjectManager || $this === self::Auditor;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }

    /** @return list<self> */
    public static function projectScoped(): array
    {
        return array_values(array_filter(self::cases(), fn (self $r) => $r->isProjectScoped()));
    }
}
