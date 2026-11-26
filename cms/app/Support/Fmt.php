<?php

namespace App\Support;

/**
 * Formatting for gateway metrics. Missing values are never shown as 0: they
 * render as "Unknown" (or "n/a" for token counts the model did not report).
 */
final class Fmt
{
    public const UNKNOWN = 'Unknown';

    public static function int(mixed $value, string $unknown = self::UNKNOWN): string
    {
        return is_numeric($value) ? number_format((float) $value, 0) : $unknown;
    }

    public static function ms(mixed $value, string $unknown = self::UNKNOWN): string
    {
        if (! is_numeric($value)) {
            return $unknown;
        }
        $ms = (float) $value;

        return $ms >= 1000 ? number_format($ms / 1000, 1).' s' : number_format($ms, 0).' ms';
    }

    /** A 0–100 percentage. */
    public static function pct(mixed $value, string $unknown = self::UNKNOWN): string
    {
        return is_numeric($value) ? number_format((float) $value, 1).'%' : $unknown;
    }

    /** A 0–1 ratio shown as a percentage. */
    public static function ratio(mixed $value, string $unknown = self::UNKNOWN): string
    {
        return is_numeric($value) ? number_format((float) $value * 100, 1).'%' : $unknown;
    }

    public static function tokens(mixed $value): string
    {
        return self::int($value, 'n/a');
    }

    public static function usd(mixed $value, string $unknown = self::UNKNOWN): string
    {
        return is_numeric($value) ? '$'.number_format((float) $value, 2) : $unknown;
    }

    public static function bytes(mixed $value, string $unknown = self::UNKNOWN): string
    {
        if (! is_numeric($value)) {
            return $unknown;
        }
        $bytes = (float) $value;
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return number_format($bytes, $unit === 'B' ? 0 : 1).' '.$unit;
            }
            $bytes /= 1024;
        }

        return $unknown;
    }

    public static function str(mixed $value, string $unknown = self::UNKNOWN): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : $unknown;
    }
}
