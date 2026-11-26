<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Domain error rendered as the PRD §06 error envelope. Messages are generic and
 * safe; never put payloads, secrets, internal URLs or stack traces in them.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $details = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('NOT_FOUND', 404, 'Not found');
    }

    public static function forbidden(string $message = 'Not permitted'): self
    {
        return new self('FORBIDDEN', 403, $message);
    }

    public static function unauthenticated(): self
    {
        return new self('UNAUTHENTICATED', 401, 'Invalid or missing credentials');
    }

    public static function invalidState(string $message): self
    {
        return new self('INVALID_STATE', 409, $message);
    }

    public static function validation(string $message, array $fields = []): self
    {
        return new self('VALIDATION_FAILED', 422, $message, $fields ? ['fields' => $fields] : []);
    }

    public function render(): JsonResponse
    {
        return self::envelope($this->errorCode, $this->getMessage(), $this->status, $this->details, $this->retryAfter);
    }

    public static function envelope(string $code, string $message, int $status, array $details = [], ?int $retryAfter = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        $response = new JsonResponse(['error' => $error, 'request_id' => RequestId::current()], $status);
        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) max(1, $retryAfter));
        }

        return $response;
    }
}
