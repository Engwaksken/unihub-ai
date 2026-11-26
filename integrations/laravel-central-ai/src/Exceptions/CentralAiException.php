<?php

namespace Unihub\CentralAi\Exceptions;

use RuntimeException;

/**
 * Gateway error with its envelope code (PRD §06). `retryable` tells callers whether
 * resubmitting later with the SAME idempotency key makes sense.
 */
class CentralAiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly ?int $retryAfter = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        return in_array($this->status, [0, 429, 502, 503, 504], true);
    }

    /** Safe text for end users; never includes gateway internals. */
    public function userMessage(): string
    {
        return match (true) {
            $this->status === 429 => 'The assistant is busy right now. Please try again in a moment.',
            $this->retryable() => 'The assistant is temporarily unavailable. Please try again shortly.',
            $this->errorCode === 'TOKEN_BUDGET' || $this->errorCode === 'VALIDATION_FAILED' => 'Your note is too long or invalid for the assistant. Please shorten it.',
            default => 'The assistant could not handle this request.',
        };
    }
}
