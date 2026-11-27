<?php

namespace App\Http\Controllers;

use App\Authorization\Permissions;
use App\Gateway\Data\Project;
use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayConflictException;
use App\Gateway\Exceptions\GatewayException;
use App\Gateway\Exceptions\GatewayNotFoundException;
use App\Gateway\Exceptions\GatewayRateLimitedException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Gateway\GatewayClient;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    protected function gateway(Request $request): GatewayClient
    {
        return app(GatewayClient::class)->as($request->user());
    }

    /**
     * The Idempotency-Key rendered into the form (see <x-idempotency-key/>).
     * A double submit of the same rendered form reuses it; a re-rendered form
     * (e.g. after a validation error) gets a fresh one.
     */
    protected function idempotencyKey(Request $request): string
    {
        $key = (string) $request->input('_idempotency_key', '');

        return Str::isUuid($key) ? $key : (string) Str::uuid();
    }

    /**
     * Run a gateway mutation, turning conflicts and rate limits into a form
     * error instead of an error page.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function mutate(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (GatewayConflictException $e) {
            $message = match ($e->errorCode) {
                'IDEMPOTENCY_CONFLICT', 'IDEMPOTENCY_REPLAY_UNAVAILABLE' => 'This form was already submitted with different values. Reload the page and try again.',
                default => $e->userMessage(),
            };
            throw ValidationException::withMessages(['form' => [$message]]);
        } catch (GatewayRateLimitedException $e) {
            $message = $e->errorCode === 'QUEUE_FULL'
                ? 'The job queue is full. Try again shortly.'
                : 'The gateway is rate limiting requests. Wait a moment and try again.';
            throw ValidationException::withMessages(['form' => [$message]]);
        }
    }

    /**
     * Redirect back after a bulk action: a success line plus the rows that were
     * skipped and why (shown by <x-flash>).
     *
     * @param  list<string>  $skipped
     */
    protected function bulkResult(string $done, array $skipped): RedirectResponse
    {
        return back()->with('status', $done)->with('bulk_skipped', array_slice($skipped, 0, 100));
    }

    /**
     * Run a gateway call for each selected row. A row the gateway refuses
     * (wrong state, not found, forbidden, invalid) is skipped with its reason;
     * an unreachable gateway stops the whole action (error page).
     *
     * @param  list<string>  $ids
     * @param  callable(string): string  $label
     * @param  callable(string): mixed  $call
     * @return array{0: int, 1: list<string>} done count, skipped lines
     */
    protected function eachRow(array $ids, callable $label, callable $call): array
    {
        $done = 0;
        $skipped = [];
        foreach ($ids as $id) {
            try {
                $call($id);
                $done++;
            } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
                throw $e;
            } catch (GatewayRateLimitedException $e) {
                $skipped[] = $label($id).': '.($e->errorCode === 'QUEUE_FULL' ? 'the job queue is full' : 'rate limited, try again shortly');
            } catch (GatewayNotFoundException) {
                $skipped[] = $label($id).': not found or outside your projects';
            } catch (GatewayException $e) {
                $skipped[] = $label($id).': '.$e->userMessage();
            }
        }

        return [$done, $skipped];
    }

    /**
     * Gateway idempotency key for one row of a bulk action: a resubmit of the
     * same rendered form replays each row instead of repeating it.
     */
    protected function rowIdempotencyKey(Request $request, string $id): string
    {
        return $this->idempotencyKey($request).':'.strtolower($id);
    }

    /**
     * @return list<string> validated, lower-cased, unique ids of the selected rows
     */
    protected function selectedIds(Request $request, int $max = 100): array
    {
        $ids = $request->input('ids');
        // Reported under `form` so <x-flash> shows it on every page.
        if (! is_array($ids) || $ids === []) {
            throw ValidationException::withMessages(['form' => ['Select at least one row first.']]);
        }
        if (count($ids) > $max) {
            throw ValidationException::withMessages(['form' => ["Select at most {$max} rows at a time."]]);
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw ValidationException::withMessages(['form' => ['The selection is invalid. Reload the page and try again.']]);
            }
        }

        return array_values(array_unique(array_map('strtolower', $ids)));
    }

    /**
     * Validated `project_id` filter. A project outside the user's scope for the
     * capability is a 404, so a project manager can never widen a report or
     * export to another project by editing the URL.
     */
    protected function projectFilter(Request $request, string $capability, string $param = 'project_id'): ?string
    {
        $value = $request->query($param, $request->input($param));
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! Str::isUuid($value)) {
            abort(404);
        }
        $this->authorizeProject($capability, $value);

        return strtolower($value);
    }

    /**
     * Projects the user may see for a capability, for filter drop-downs and
     * name lookups. Returns [] if the gateway cannot be reached (the page's own
     * gateway call then shows the unreachable state).
     *
     * @return array<string, Project> uuid => project
     */
    protected function projectOptions(Request $request, string $capability): array
    {
        $user = $request->user();
        $scope = Permissions::projectScope($user, $capability);
        if ($scope === []) {
            return [];
        }

        // Memoised per request: one listing serves every drop-down on the page.
        $all = $request->attributes->get('_gateway_projects');
        if (! is_array($all)) {
            $all = [];
            try {
                $client = $this->gateway($request);
                $cursor = null;
                $pages = 0;
                do {
                    $page = $client->listProjects([], $cursor, 100);
                    foreach ($page->items as $project) {
                        $all[$project->id] = $project;
                    }
                    $cursor = $page->nextCursor;
                } while ($cursor !== null && ++$pages < 10);
            } catch (GatewayUnavailableException|GatewayAuthenticationException) {
                $all = [];
            }
            $request->attributes->set('_gateway_projects', $all);
        }

        $options = $all;
        if ($scope !== null) {
            $options = array_intersect_key($options, array_flip($scope));
        }
        uasort($options, fn (Project $a, Project $b) => strcasecmp($a->name, $b->name));

        return $options;
    }

    /**
     * Converts a Y-m-d date in the display time zone to a UTC ISO instant.
     */
    protected function dayBoundary(?string $date, bool $endOfDay): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        $day = CarbonImmutable::createFromFormat('Y-m-d', $date, DisplayTime::zone());
        if ($day === null) {
            return null;
        }
        $day = $endOfDay ? $day->endOfDay() : $day->startOfDay();

        return $day->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return array<string, list<string>>
     */
    protected function dateRangeRules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * Single-resource check: anything out of the user's scope is a 404 so that
     * other projects' ids are not revealed (docs/permissions.md).
     */
    protected function authorizeProject(string $capability, string $projectUuid): void
    {
        abort_unless(Gate::allows($capability, strtolower($projectUuid)), 404);
    }
}
