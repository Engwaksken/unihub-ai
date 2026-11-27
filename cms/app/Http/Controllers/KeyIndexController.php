<?php

namespace App\Http\Controllers;

use App\Authorization\Permissions;
use App\Gateway\Data\Project;
use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayNotFoundException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Global "API keys" page. The gateway has no cross-project key listing, so
 * this pages through the user's visible projects (PER_PAGE at a time) and
 * asks for each project's keys: at most PER_PAGE + 1 gateway calls per page.
 * Issuing, rotating and revoking happen on each project's API keys tab.
 */
class KeyIndexController extends Controller
{
    public const PER_PAGE = 10;

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'cursor' => ['nullable', 'string', 'max:512'],
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,expired,revoked'],
        ]);
        $status = $filters['status'] ?? null;
        $scope = Permissions::projectScope($request->user(), 'key.view');

        $groups = [];
        $next = null;
        $error = null;

        if ($scope !== []) {
            try {
                $client = $this->gateway($request);
                $page = $client->listProjects(['q' => ($filters['q'] ?? '') !== '' ? $filters['q'] : null], $request->query('cursor'), self::PER_PAGE);
                $next = $page->nextCursor;
                foreach ($page->items as $project) {
                    /** @var Project $project */
                    if ($scope !== null && ! in_array($project->id, $scope, true)) {
                        continue;
                    }
                    try {
                        $keys = $client->list('/projects/'.rawurlencode($project->id).'/keys', [], null, 100);
                        $items = $status === null ? $keys->items
                            : array_values(array_filter($keys->items, fn (array $k) => ($k['status'] ?? null) === $status));
                        if ($status !== null && $items === []) {
                            continue;
                        }
                        $groups[] = ['project' => $project, 'keys' => $items, 'more' => $keys->nextCursor !== null];
                    } catch (GatewayNotFoundException) {
                        continue;
                    }
                }
            } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
                $error = $e;
            }
        }

        return view('keys.index', [
            'filters' => $filters,
            'stats' => $error === null ? self::stats($groups) : null,
            'groups' => $groups,
            'next' => $next,
            'cursor' => $request->query('cursor'),
            'error' => $error,
            'unassigned' => $scope === [],
        ]);
    }

    /**
     * Counts over the keys shown on this page.
     *
     * @param  list<array{keys: list<array<string, mixed>>}>  $groups
     * @return array<string, int>
     */
    private static function stats(array $groups): array
    {
        $soon = now()->addDays(14);
        $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'never_used' => 0, 'expiring' => 0];
        foreach ($groups as $g) {
            foreach ($g['keys'] as $k) {
                $stats['total']++;
                $active = ($k['status'] ?? null) === 'active';
                $stats[$active ? 'active' : 'inactive']++;
                $stats['never_used'] += $active && empty($k['last_used_at']) ? 1 : 0;
                $stats['expiring'] += $active && ! empty($k['expires_at']) && Carbon::parse($k['expires_at'])->lte($soon) ? 1 : 0;
            }
        }

        return $stats;
    }
}
