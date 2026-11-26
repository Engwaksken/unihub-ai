<?php

namespace App\Http\Middleware;

use App\Management\Actor;
use App\Management\AssertionVerifier;
use App\Services\Secrets;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Two-layer management auth (ADR D11): service token + signed staff assertion. */
class AuthenticateManagement
{
    public function __construct(private readonly Secrets $secrets, private readonly AssertionVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('gateway.management.allowed_ips');
        if ($allowed !== [] && ! in_array($request->ip(), $allowed, true)) {
            throw ApiException::notFound();
        }

        $token = $request->bearerToken();
        $digests = $this->secrets->mgmtTokenDigests();
        $ok = false;
        if (is_string($token) && str_starts_with($token, 'uhm_') && $digests !== []) {
            $digest = $this->secrets->hmac($token);
            foreach ($digests as $d) {
                $ok = hash_equals($d, $digest) || $ok;
            }
        }
        if (! $ok) {
            throw ApiException::unauthenticated();
        }

        $actor = $this->verifier->verify($request->header('X-Actor-Assertion'));
        $request->attributes->set('actor', $actor);
        app()->instance(Actor::class, $actor);

        return $next($request);
    }
}
