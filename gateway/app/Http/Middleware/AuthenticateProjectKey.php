<?php

namespace App\Http\Middleware;

use App\Services\KeyService;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Project identity comes ONLY from the validated key (PRD §04). */
class AuthenticateProjectKey
{
    public function __construct(private readonly KeyService $keys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->keys->verify($request->bearerToken());
        if ($key === null) {
            throw ApiException::unauthenticated();
        }
        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
