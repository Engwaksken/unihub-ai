<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/** Correlation id for the current request (or worker iteration). */
final class RequestId
{
    public static function current(): string
    {
        $id = Context::get('request_id');
        if (! is_string($id) || $id === '') {
            $id = (string) Str::uuid();
            Context::add('request_id', $id);
        }

        return $id;
    }

    public static function set(string $id): void
    {
        Context::add('request_id', $id);
    }

    public static function isValid(?string $id): bool
    {
        return is_string($id) && Str::isUuid($id);
    }
}
