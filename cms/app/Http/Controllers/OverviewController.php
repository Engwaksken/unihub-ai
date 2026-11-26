<?php

namespace App\Http\Controllers;

use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Gateway\Exceptions\GatewayValidationException;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Overview cards and trend from GET /summary (scoped by the gateway to the
 * actor; the project filter is checked here as well).
 */
class OverviewController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            ...$this->dateRangeRules(),
            'project_id' => ['nullable', 'uuid'],
        ]);
        $projectId = $this->projectFilter($request, 'overview.view');

        $today = CarbonImmutable::now(DisplayTime::zone());
        $filters['from'] ??= $today->subDays(6)->format('Y-m-d');
        $filters['to'] ??= $today->format('Y-m-d');

        $summary = null;
        $error = null;
        try {
            $summary = $this->gateway($request)->cachedData('/summary', [
                'from' => $this->dayBoundary($filters['from'], false),
                'to' => $this->dayBoundary($filters['to'], true),
                'project_id' => $projectId,
            ]);
        } catch (GatewayUnavailableException|GatewayAuthenticationException $e) {
            $error = $e;
        } catch (GatewayValidationException $e) {
            $error = $e;
        }

        return view('overview.index', [
            'summary' => $summary,
            'error' => $error,
            'filters' => $filters,
            'projectId' => $projectId,
            'projects' => $this->projectOptions($request, 'overview.view'),
        ]);
    }
}
