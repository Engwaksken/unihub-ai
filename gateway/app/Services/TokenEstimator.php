<?php

namespace App\Services;

/**
 * Conservative token estimate (ADR D8): ceil(UTF-8 bytes / 3) plus chat-template
 * overhead. Qwen2.5 averages ~3.5–4 bytes/token on English, so /3 over-estimates.
 * Calibrate against the real tokenizer during the P4 benchmark.
 */
class TokenEstimator
{
    public const TEMPLATE_OVERHEAD = 16;

    public function estimate(string $systemTemplate, string $input): int
    {
        return (int) ceil(strlen($systemTemplate) / 3) + (int) ceil(strlen($input) / 3) + self::TEMPLATE_OVERHEAD;
    }
}
