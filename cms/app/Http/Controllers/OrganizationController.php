<?php

namespace App\Http\Controllers;

use App\Gateway\Data\Organization;
use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Gateway\GatewayClient;
use App\Gateway\GatewayPage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);

        $page = null;
        $error = null;
        $stats = null;
        try {
            $client = $this->gateway($request);
            $page = $client->listOrganizations(
                ['q' => $filters['q'] ?? null, 'status' => $filters['status'] ?? null],
                $filters['cursor'] ?? null,
            );
            $unfiltered = empty($filters['q']) && empty($filters['status']) && empty($filters['cursor']) && $page->nextCursor === null;
            $stats = $this->stats($client, $unfiltered ? $page : null);
        } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
            $error = $e;
        }

        return view('organizations.index', [
            'page' => $page,
            'error' => $error,
            'filters' => $filters,
            'stats' => $stats,
        ]);
    }

    /**
     * Counts over every organization (not just this page), for the statistics cards.
     *
     * @param  GatewayPage<Organization>|null  $complete
     * @return array{total: int, active: int, archived: int, projects: int}
     */
    private function stats(GatewayClient $client, ?GatewayPage $complete = null): array
    {
        $stats = ['total' => 0, 'active' => 0, 'archived' => 0, 'projects' => 0];
        $cursor = null;
        $pages = 0;
        do {
            // An unfiltered first page with no next page already holds every organization.
            $page = $complete ?? $client->listOrganizations([], $cursor, 100);
            $complete = null;
            foreach ($page->items as $org) {
                $stats['total']++;
                $stats[$org->isArchived() ? 'archived' : 'active']++;
                $stats['projects'] += (int) ($org->projectCount ?? 0);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null && ++$pages < 10);

        return $stats;
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('createOrganization', [
            'name' => ['required', 'string', 'max:120'],
        ]);

        $org = $this->mutate(fn () => $this->gateway($request)->createOrganization(trim($data['name']), $this->idempotencyKey($request)));

        return redirect()->route('organizations.index')->with('status', "Organization “{$org->name}” created.");
    }

    public function update(Request $request, string $organization): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $org = $this->mutate(fn () => $this->gateway($request)->updateOrganization($organization, ['name' => trim($data['name'])], $this->idempotencyKey($request)));

        return redirect()->route('organizations.index')->with('status', "Organization renamed to “{$org->name}”.");
    }

    public function updateStatus(Request $request, string $organization): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'archived'])],
        ]);

        $org = $this->mutate(fn () => $this->gateway($request)->updateOrganization($organization, ['status' => $data['status']], $this->idempotencyKey($request)));

        $verb = $data['status'] === 'archived' ? 'archived' : 'restored';

        return redirect()->route('organizations.index')->with('status', "Organization “{$org->name}” {$verb}.");
    }
}
