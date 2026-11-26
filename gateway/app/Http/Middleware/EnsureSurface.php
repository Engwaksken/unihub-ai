<?php

namespace App\Http\Middleware;

use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-surface separation (ADR D6): nginx sets FastCGI param GATEWAY_SURFACE per
 * vhost. Public vhost can never reach /internal/* and vice versa — we answer 404.
 */
class EnsureSurface
{
    public function handle(Request $request, Closure $next, string $surface): Response
    {
        $actual = $request->server('GATEWAY_SURFACE');
        if ($actual === null || $actual === '') {
            if (! config('gateway.allow_any_surface')) {
                throw ApiException::notFound();
            }
        } elseif ($actual !== $surface) {
            throw ApiException::notFound();
        }

        return $next($request);
    }
}
