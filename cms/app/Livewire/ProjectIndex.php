<?php

namespace App\Livewire;

use App\Authorization\Permissions;
use App\Gateway\Data\Organization;
use App\Gateway\Data\Project;
use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Gateway\Exceptions\GatewayValidationException;
use App\Gateway\GatewayClient;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Project list with search, status/organization filters and cursor paging.
 * Project managers and auditors only ever see their assigned projects: the
 * gateway filters by the assertion, and this component filters again.
 */
class ProjectIndex extends Component
{
    public const PER_PAGE = 25;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'organization', except: '')]
    public string $organizationId = '';

    /** Cursor of the page being shown ('' = first page). */
    public string $cursor = '';

    /** Cursor for the following page, as returned by the gateway. */
    public string $nextCursor = '';

    /** @var list<string> cursors of earlier pages, for "Previous". */
    public array $previousCursors = [];

    public function updatedSearch(): void
    {
        $this->resetPaging();
    }

    public function updatedStatus(): void
    {
        $this->resetPaging();
    }

    public function updatedOrganizationId(): void
    {
        $this->resetPaging();
    }

    public function nextPage(): void
    {
        if ($this->nextCursor === '') {
            return;
        }
        $this->previousCursors[] = $this->cursor;
        $this->cursor = Str::limit($this->nextCursor, 512, '');
    }

    public function previousPage(): void
    {
        $this->cursor = (string) (array_pop($this->previousCursors) ?? '');
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->organizationId = '';
        $this->resetPaging();
    }

    public function render(): View
    {
        $user = Auth::user();
        abort_unless($user && Gate::allows('project.view'), 403);

        $scope = Permissions::projectScope($user, 'project.view');
        $status = in_array($this->status, Project::STATUSES, true) ? $this->status : '';
        $organizationId = Str::isUuid($this->organizationId) ? strtolower($this->organizationId) : '';
        $search = Str::limit(trim($this->search), 120, '');

        $projects = [];

        $error = null;
        $organizations = [];
        $stats = null;

        if ($scope === []) {
            // Not assigned to any project: nothing to ask the gateway for.
            return view('livewire.project-index', [
                'projects' => [], 'error' => null, 'unassigned' => true,
                'organizations' => [], 'canFilterByOrganization' => false, 'filtered' => false,
                'canCreate' => false, 'stats' => null, 'activeOrganizations' => [],
            ]);
        }

        $client = app(GatewayClient::class)->as($user);

        try {
            $page = $client->listProjects([
                'q' => $search ?: null,
                'status' => $status ?: null,
                'organization_id' => $organizationId ?: null,
            ], $this->cursor ?: null, self::PER_PAGE);

            if ($scope !== null) {
                $allowed = array_flip($scope);
                $page = $page->filter(fn (Project $p) => isset($allowed[$p->id]));
            }

            $projects = $page->items;

            $this->nextCursor = (string) $page->nextCursor;

            if (Gate::allows('organization.view')) {
                $organizations = $client->listOrganizations([], null, 100)->items;
                usort($organizations, fn (Organization $a, Organization $b) => strcasecmp($a->name, $b->name));
            }
            $stats = $this->stats($client, $scope);
        } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
            $this->nextCursor = '';
            $error = 'unreachable';
        } catch (GatewayValidationException $e) {
            $error = 'invalid-filter';
        }

        return view('livewire.project-index', [
            'projects' => $projects,
            'error' => $error,
            'unassigned' => false,
            'organizations' => $organizations,
            'canFilterByOrganization' => Gate::allows('organization.view'),
            'filtered' => $search !== '' || $status !== '' || $organizationId !== '',
            'canCreate' => Gate::allows('project.create'),
            'stats' => $stats,
            'activeOrganizations' => array_values(array_filter($organizations, fn (Organization $o) => $o->status === 'active')),
            'canSetQuotas' => Gate::allows('project.change_quotas'),
            'canSetAllowlist' => Gate::allows('project.change_allowlist'),
        ]);
    }

    /**
     * Project counts by status for the statistics cards, over every project the
     * user may see (not just this page). Cached briefly per user so typing in the
     * search box does not re-list everything.
     *
     * @param  list<string>|null  $scope
     * @return array<string, int>
     */
    private function stats(GatewayClient $client, ?array $scope): array
    {
        return Cache::remember('project-stats:'.Auth::id(), 60, function () use ($client, $scope) {
            $counts = array_fill_keys(['total', ...Project::STATUSES, 'production'], 0);
            $allowed = $scope === null ? null : array_flip($scope);
            $cursor = null;
            $pages = 0;
            do {
                $page = $client->listProjects([], $cursor, 100);
                foreach ($page->items as $p) {
                    if ($allowed !== null && ! isset($allowed[$p->id])) {
                        continue;
                    }
                    $counts['total']++;
                    $counts[$p->status] = ($counts[$p->status] ?? 0) + 1;
                    $counts['production'] += $p->environment === 'production' ? 1 : 0;
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null && ++$pages < 10);

            return $counts;
        });
    }

    private function resetPaging(): void
    {
        $this->cursor = '';
        $this->previousCursors = [];
    }
}
