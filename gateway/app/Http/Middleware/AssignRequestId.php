<?php

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Request-Id');
        RequestId::set(RequestId::isValid($incoming) ? strtolower($incoming) : (string) Str::uuid());

        $response = $next($request);
        $response->headers->set('X-Request-Id', RequestId::current());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private'); // API responses are never cacheable

        return $response;
    }
}
