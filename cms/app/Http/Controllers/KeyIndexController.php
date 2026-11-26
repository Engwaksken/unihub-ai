<?php

namespace App\Http\Controllers;

use App\Authorization\Permissions;
use App\Gateway\Data\Project;
use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayNotFoundException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

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
        $request->validate(['cursor' => ['nullable', 'string', 'max:512']]);
        $scope = Permissions::projectScope($request->user(), 'key.view');

        $groups = [];
        $next = null;
        $error = null;

        if ($scope !== []) {
            try {
                $client = $this->gateway($request);
                $page = $client->listProjects([], $request->query('cursor'), self::PER_PAGE);
                $next = $page->nextCursor;
                foreach ($page->items as $project) {
                    /** @var Project $project */
                    if ($scope !== null && ! in_array($project->id, $scope, true)) {
                        continue;
                    }
                    try {
                        $keys = $client->list('/projects/'.rawurlencode($project->id).'/keys', [], null, 100);
                        $groups[] = ['project' => $project, 'keys' => $keys->items, 'more' => $keys->nextCursor !== null];
                    } catch (GatewayNotFoundException) {
                        continue;
                    }
                }
            } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
                $error = $e;
            }
        }

        return view('keys.index', [
            'groups' => $groups,
            'next' => $next,
            'cursor' => $request->query('cursor'),
            'error' => $error,
            'unassigned' => $scope === [],
        ]);
    }
}
