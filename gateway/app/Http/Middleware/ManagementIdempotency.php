<?php

namespace App\Http\Middleware;

use App\Management\Actor;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key for management mutations (docs/api/management-auth.md).
 * Responses flagged with X-Idempotency-No-Replay (key issuance) are recorded but
 * never replayed, because the secret cannot be shown twice.
 */
class ManagementIdempotency
{
    public const NO_REPLAY_HEADER = 'X-Idempotency-No-Replay';

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key)) {
            throw ApiException::validation('Idempotency-Key header is required', ['Idempotency-Key' => ['required']]);
        }
        /** @var Actor $actor */
        $actor = $request->attributes->get('actor');
        $hash = hash('sha256', $request->getMethod().' '.$request->path()."\n".$request->getContent());

        $stored = DB::table('mgmt_idempotency')->where(['key' => $key, 'actor_ref' => $actor->ref()])->first();
        if ($stored !== null) {
            if (! hash_equals($stored->request_hash, $hash)) {
                throw new ApiException('IDEMPOTENCY_CONFLICT', 409, 'Idempotency-Key reused with a different request');
            }
            if ($stored->body === null) {
                throw new ApiException('IDEMPOTENCY_REPLAY_UNAVAILABLE', 409, 'This action cannot be replayed; check current state');
            }

            return new JsonResponse(json_decode($stored->body, true), $stored->status, ['Idempotent-Replay' => 'true']);
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            $noReplay = $response->headers->has(self::NO_REPLAY_HEADER);
            $response->headers->remove(self::NO_REPLAY_HEADER);
            DB::table('mgmt_idempotency')->insertOrIgnore([
                'key' => $key,
                'actor_ref' => $actor->ref(),
                'request_hash' => $hash,
                'status' => $response->getStatusCode(),
                'body' => $noReplay ? null : $response->getContent(),
                'created_at' => now(),
            ]);
        }

        return $response;
    }
}
