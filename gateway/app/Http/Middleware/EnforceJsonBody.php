<?php

namespace App\Http\Middleware;

use App\Services\Settings;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 32 KiB body cap (413) and JSON-only bodies (415) for write requests. */
class EnforceJsonBody
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            $limit = $this->settings->int('max_body_bytes');
            $declared = (int) $request->header('Content-Length', '0');
            if ($declared > $limit || strlen((string) $request->getContent()) > $limit) {
                throw new ApiException('PAYLOAD_TOO_LARGE', 413, 'Request body too large');
            }
            $content = (string) $request->getContent();
            if ($content !== '' && ! $request->isJson()) {
                throw new ApiException('UNSUPPORTED_MEDIA_TYPE', 415, 'Content-Type must be application/json');
            }
            if ($content !== '' && json_decode($content) === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new ApiException('INVALID_REQUEST', 400, 'Malformed JSON body');
            }
        }

        return $next($request);
    }
}
