<?php

namespace App\Support;

/** Crockford base32 (no I, L, O, U; no underscores) for public key prefixes. */
final class Base32
{
    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    public static function random(int $length): string
    {
        $out = '';
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[ord($bytes[$i]) & 31];
        }

        return $out;
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $value): string|false
    {
        if (! preg_match('/^[A-Za-z0-9_-]*$/', $value)) {
            return false;
        }

        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
