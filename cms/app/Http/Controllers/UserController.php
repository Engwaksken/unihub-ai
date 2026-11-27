<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Gateway\Data\Project;
use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Models\User;
use App\Services\StaffDirectory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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

    public const STATUSES = ['active' => 'Active', 'invited' => 'Invited', 'mfa-pending' => '2FA pending', 'disabled' => 'Disabled'];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::in(Role::values())],
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
        ]);

        $query = User::query()->with(['roleAssignments', 'memberships', 'invitations' => fn ($q) => $q->latest()]);
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }
        if (! empty($filters['role'])) {
            $query->whereHas('roleAssignments', fn ($r) => $r->where('role', $filters['role']));
        }
        if (! empty($filters['status'])) {
            $this->whereStatus($query, $filters['status']);
        }
        $users = $query->orderBy('name')->paginate(25)->withQueryString();

        $sessionCounts = DB::table(config('session.table', 'sessions'))
            ->whereIn('user_id', $users->pluck('id'))
            ->select('user_id', DB::raw('count(*) as total'), DB::raw('max(last_activity) as last_activity'))
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        [$projects, $projectsError] = $this->projectChoices($request);

        return view('users.index', [
            'users' => $users,
            'sessions' => $sessionCounts,
            'filters' => $filters,
            'stats' => $this->stats(),
            'roles' => Role::cases(),
            'projects' => $projects,
            'projectsError' => $projectsError,
        ]);
    }

    /**
     * Headline counts over every human account (the system account is excluded).
     *
     * @return array<string, int>
     */
    private function stats(): array
    {
        $count = function (?string $status): int {
            $q = User::query()->where('is_system', false);
            if ($status !== null) {
                $this->whereStatus($q, $status);
            }

            return $q->count();
        };

        return [
            'total' => $count(null),
            'active' => $count('active'),
            'invited' => $count('invited'),
            'mfa-pending' => $count('mfa-pending'),
            'disabled' => $count('disabled'),
            'online' => (int) DB::table(config('session.table', 'sessions'))
                ->whereNotNull('user_id')
                ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp())
                ->distinct()->count('user_id'),
        ];
    }

    /** Mirrors the status badge logic in users/index.blade.php. */
    private function whereStatus(Builder $q, string $status): void
    {
        match ($status) {
            'disabled' => $q->whereNotNull('disabled_at'),
            'invited' => $q->whereNull('disabled_at')->where('is_system', false)->whereNull('password_set_at'),
            'mfa-pending' => $q->whereNull('disabled_at')->where('is_system', false)->whereNotNull('password_set_at')
                ->where(fn ($w) => $w->whereNull('two_factor_secret')->orWhereNull('two_factor_confirmed_at')),
            default => $q->whereNull('disabled_at')->where('is_system', false)->whereNotNull('password_set_at')
                ->whereNotNull('two_factor_secret')->whereNotNull('two_factor_confirmed_at'),
        };
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
            [$user, $token] = $this->staff->invite($data['email'], trim($data['name']), $roles, $memberships, $request->user(), notify: false);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['roles' => [$e->getMessage()]]);
        }

        return $this->afterInvitation($user, $token, "Invitation sent to {$user->email}.");
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

    /**
     * Act on the selected accounts. Every rule of the single-account actions
     * still applies per account (not yourself, not the system account, not the
     * last owner, delete only invited or disabled accounts); an account that
     * breaks one is skipped with the reason.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['delete', 'disable', 'enable', 'role'])],
            'role' => ['nullable', Rule::in(Role::values())],
            'role_change' => ['nullable', Rule::in(['add', 'remove'])],
        ]);
        $ids = $this->selectedIds($request);
        $actor = $request->user();

        // Each action: what to do to one account, and the summary line ({n} accounts).
        if ($data['action'] === 'role') {
            $role = Role::tryFrom((string) ($data['role'] ?? ''));
            if ($role === null || ! isset($data['role_change'])) {
                throw ValidationException::withMessages(['form' => ['Choose the role and whether to add or remove it.']]);
            }
            $add = $data['role_change'] === 'add';
            $apply = function (User $u) use ($role, $add, $actor): void {
                $this->staff->changeRole($u, $role, $add, $actor);
            };
            $summary = $add ? "Added the {$role->label()} role to {n}." : "Removed the {$role->label()} role from {n}.";
        } else {
            $action = $data['action'];
            $apply = function (User $u) use ($action, $actor): void {
                if ($action === 'delete') {
                    $this->staff->delete($u, $actor);
                } elseif ($action === 'disable') {
                    if ($u->isDisabled()) {
                        throw new InvalidArgumentException('Already disabled.');
                    }
                    $this->staff->disable($u, $actor);
                } else {
                    if (! $u->isDisabled()) {
                        throw new InvalidArgumentException('Already enabled.');
                    }
                    $this->staff->enable($u, $actor);
                }
            };
            $summary = match ($action) {
                'delete' => 'Deleted {n}. Their history stays in the audit log.',
                'disable' => 'Disabled {n} and signed them out everywhere.',
                default => 'Re-enabled {n}.',
            };
        }

        $users = User::query()->with(['roleAssignments', 'memberships'])->whereIn('id', $ids)->get();
        $done = 0;
        $skipped = [];
        foreach ($users as $user) {
            try {
                $apply($user);
                $done++;
            } catch (InvalidArgumentException $e) {
                $skipped[] = "{$user->email}: {$e->getMessage()}";
            }
        }
        $missing = count($ids) - $users->count();
        if ($missing > 0) {
            $skipped[] = "{$missing} selected ".Str::plural('account', $missing).' no longer '.($missing === 1 ? 'exists' : 'exist').'.';
        }

        $message = str_replace('{n}', $done.' '.Str::plural('account', $done), $summary);

        return $this->bulkResult($message, $skipped);
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
            $token = $this->staff->resendInvitation($user, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return $this->afterInvitation($user, $token, "A new invitation link was sent to {$user->email}.");
    }

    /**
     * The account and link exist either way. If the e-mail fails, the owner sees
     * why and gets the link once, to fix mail or hand it over another way.
     */
    private function afterInvitation(User $user, string $token, string $sentMessage): RedirectResponse
    {
        $error = $this->staff->sendInvitation($user, $token);
        $redirect = redirect()->route('users.index');

        return $error === null
            ? $redirect->with('status', $sentMessage)
            : $redirect->with('invitation_mail_failed', [
                'email' => $user->email,
                'error' => $error,
                'link' => route('invitations.show', ['token' => $token]),
            ]);
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
