<?php

namespace App\Gateway\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for every failure talking to the gateway management API. It
 * carries the parsed error envelope (docs/api/management-auth.md):
 * {"error": {"code", "message", "details"?}, "request_id"}.
 */
class GatewayException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly array $details = [],
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * A message that is safe to show to staff. Gateway messages are shown as
     * plain (escaped) text; they never contain secrets by contract.
     */
    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
