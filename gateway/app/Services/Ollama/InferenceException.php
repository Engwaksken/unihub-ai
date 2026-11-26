<?php

namespace App\Services\Ollama;

use RuntimeException;

class InferenceException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly bool $transient)
    {
        parent::__construct($message);
    }
}
