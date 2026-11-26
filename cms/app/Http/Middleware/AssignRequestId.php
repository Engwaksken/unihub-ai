<?php

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates a fresh request id for every CMS request (client-supplied values
 * are ignored) and returns it in the X-Request-Id response header.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = RequestId::regenerate();

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
