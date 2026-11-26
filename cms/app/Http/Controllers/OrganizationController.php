<?php

namespace App\Http\Controllers;

use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayUnavailableException;
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
        try {
            $page = $this->gateway($request)->listOrganizations(
                ['q' => $filters['q'] ?? null, 'status' => $filters['status'] ?? null],
                $filters['cursor'] ?? null,
            );
        } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
            $error = $e;
        }

        return view('organizations.index', [
            'page' => $page,
            'error' => $error,
            'filters' => $filters,
        ]);
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
