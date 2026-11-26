<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Gateway\Data\Project;
use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Models\User;
use App\Services\StaffDirectory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Staff management (owner only, enforced by the can:staff.manage route
 * middleware).
 */
class UserController extends Controller
{
    public function __construct(private readonly StaffDirectory $staff) {}

    public function index(): View
    {
        $users = User::query()
            ->with(['roleAssignments', 'memberships', 'invitations' => fn ($q) => $q->latest()])
            ->orderBy('name')
            ->paginate(50);

        $sessionCounts = DB::table(config('session.table', 'sessions'))
            ->whereIn('user_id', $users->pluck('id'))
            ->select('user_id', DB::raw('count(*) as total'), DB::raw('max(last_activity) as last_activity'))
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        return view('users.index', ['users' => $users, 'sessions' => $sessionCounts]);
    }

    public function create(Request $request): View
    {
        [$projects, $projectsError] = $this->projectChoices($request);

        return view('users.create', [
            'roles' => Role::cases(),
            'projects' => $projects,
            'projectsError' => $projectsError,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            ...$this->accessRules(),
        ]);

        [$roles, $memberships] = $this->parseAccess($data);

        try {
            [$user] = $this->staff->invite($data['email'], trim($data['name']), $roles, $memberships, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['roles' => [$e->getMessage()]]);
        }

        return redirect()->route('users.index')->with('status', "Invitation sent to {$user->email}.");
    }

    public function edit(Request $request, User $user): View
    {
        abort_if($user->isSystem(), 403, 'The system account has fixed access (see cms:system-user).');
        [$projects, $projectsError] = $this->projectChoices($request);

        return view('users.edit', [
            'subject' => $user->load(['roleAssignments', 'memberships']),
            'roles' => Role::cases(),
            'projects' => $projects,
            'projectsError' => $projectsError,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_if($user->isSystem(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            ...$this->accessRules(),
        ]);

        [$roles, $memberships] = $this->parseAccess($data);

        try {
            if ($user->name !== trim($data['name'])) {
                $user->forceFill(['name' => trim($data['name'])])->save();
            }
            $this->staff->updateAccess($user, $roles, $memberships, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['roles' => [$e->getMessage()]]);
        }

        return redirect()->route('users.index')->with('status', "Access for {$user->email} updated.");
    }

    public function disable(Request $request, User $user): RedirectResponse
    {
        try {
            $this->staff->disable($user, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return back()->with('status', "{$user->email} has been disabled and signed out everywhere.");
    }

    public function enable(Request $request, User $user): RedirectResponse
    {
        $this->staff->enable($user, $request->user());

        return back()->with('status', "{$user->email} has been re-enabled.");
    }

    public function revokeSessions(Request $request, User $user): RedirectResponse
    {
        $count = $this->staff->revokeSessions($user, $request->user());

        if ($user->is($request->user())) {
            return redirect()->route('login')->with('status', 'All of your sessions were signed out.');
        }

        return back()->with('status', "Signed {$user->email} out of {$count} ".($count === 1 ? 'session' : 'sessions').'.');
    }

    public function resendInvitation(Request $request, User $user): RedirectResponse
    {
        try {
            $this->staff->resendInvitation($user, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return back()->with('status', "A new invitation link was sent to {$user->email}.");
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function accessRules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(Role::values())],
            'memberships' => ['nullable', 'array', 'max:500'],
            'memberships.*' => ['string', Rule::in(['none', Role::ProjectManager->value, Role::Auditor->value])],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: list<Role>, 1: array<string, Role>}
     */
    private function parseAccess(array $data): array
    {
        $roles = array_map(fn ($r) => Role::from($r), array_values(array_unique($data['roles'])));

        $memberships = [];
        foreach ($data['memberships'] ?? [] as $uuid => $value) {
            if ($value === 'none') {
                continue;
            }
            if (! is_string($uuid) || ! Str::isUuid($uuid)) {
                throw ValidationException::withMessages(['memberships' => ['Invalid project reference.']]);
            }
            $role = Role::from($value);
            if (! in_array($role, $roles, true)) {
                throw ValidationException::withMessages(['memberships' => ["Assigning a project as {$role->label()} requires the {$role->label()} role."]]);
            }
            $memberships[strtolower($uuid)] = $role;
        }

        return [$roles, $memberships];
    }

    /**
     * All gateway projects, for the membership editor. Returns an error
     * instead of throwing so the rest of the form still works offline.
     *
     * @return array{0: list<Project>|null, 1: string|null}
     */
    private function projectChoices(Request $request): array
    {
        try {
            $client = $this->gateway($request);
            $projects = [];
            $cursor = null;
            $pages = 0;
            do {
                $page = $client->listProjects([], $cursor, 100);
                array_push($projects, ...$page->items);
                $cursor = $page->nextCursor;
            } while ($cursor !== null && ++$pages < 20);

            usort($projects, fn (Project $a, Project $b) => strcasecmp(($a->organizationName ?? '').$a->name, ($b->organizationName ?? '').$b->name));

            return [$projects, null];
        } catch (GatewayUnavailableException|GatewayAuthenticationException) {
            return [null, 'The gateway is unreachable, so the project list cannot be loaded. Existing memberships are kept; new project assignments can be made once the gateway is back.'];
        }
    }
}
