<?php

namespace App\Management;

use App\Support\ApiException;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Staff member acting through the CMS, from a verified actor assertion (ADR D11).
 *
 * 'all' grants come from any role the actor holds (e.g. owner, or project_manager for
 * model.view). 'assigned' grants are resolved PER PROJECT from `project_roles`
 * ({project uuid: role}), so a project manager on A who is only auditor on B gets
 * auditor rights — not project-manager rights — on B.
 */
final class Actor
{
    /**
     * @param  string[]  $roles  all roles held (global + project-scoped)
     * @param  array<string,string>  $projectRoles  project uuid => project-scoped role
     */
    public function __construct(
        public readonly string $sub,
        public readonly ?string $name,
        public readonly array $roles,
        public readonly array $projectRoles,
    ) {}

    public function ref(): string
    {
        return 'cms:'.$this->sub;
    }

    /** Projects on which this actor holds the capability through a project-scoped role. */
    public function projectsWith(string $capability): array
    {
        $grants = Capabilities::MATRIX[$capability] ?? [];

        return array_keys(array_filter($this->projectRoles, fn (string $role) => ($grants[$role] ?? null) === 'assigned'));
    }

    /** 'all', 'assigned' (on at least one project) or null. */
    public function scopeFor(string $capability): ?string
    {
        $grants = Capabilities::MATRIX[$capability] ?? [];
        foreach ($this->roles as $role) {
            if (($grants[$role] ?? null) === 'all') {
                return 'all';
            }
        }
        foreach ($this->projectRoles as $role) {
            if (($grants[$role] ?? null) === 'assigned') {
                return 'assigned';
            }
        }

        return null;
    }

    public function can(string $capability, ?string $projectId = null): bool
    {
        $scope = $this->scopeFor($capability);
        if ($scope === 'all') {
            return true;
        }
        if ($scope === null) {
            return false;
        }

        return $projectId === null || in_array($projectId, $this->projectsWith($capability), true);
    }

    /**
     * FORBIDDEN when no role grants the capability at all; NOT_FOUND when the project is
     * outside the actor's scope for it (don't reveal other projects' existence).
     */
    public function authorize(string $capability, ?string $projectId = null): void
    {
        $scope = $this->scopeFor($capability);
        if ($scope === null) {
            throw ApiException::forbidden();
        }
        if ($scope === 'assigned' && $projectId !== null && ! in_array($projectId, $this->projectsWith($capability), true)) {
            throw $this->visibleProject($projectId) ? ApiException::forbidden() : ApiException::notFound();
        }
    }

    /** Restrict a query to the projects visible for this capability. */
    public function scopeQuery(Builder $query, string $capability, string $column = 'project_id'): Builder
    {
        $scope = $this->scopeFor($capability);
        if ($scope === null) {
            throw ApiException::forbidden();
        }
        if ($scope === 'assigned') {
            $query->whereIn($column, $this->projectsWith($capability) ?: ['__none__']);
        }

        return $query;
    }

    public function isGlobal(string $capability): bool
    {
        return $this->scopeFor($capability) === 'all';
    }

    /** Projects this actor is a member of in any role (for list scoping of org/project views). */
    public function memberProjects(): array
    {
        return array_keys($this->projectRoles);
    }

    private function visibleProject(string $projectId): bool
    {
        return array_key_exists($projectId, $this->projectRoles);
    }
}
