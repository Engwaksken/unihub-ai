<?php

namespace App\Support;

use App\Services\CmsSettings;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Converts stored/received UTC timestamps to the display time zone
 * (config app.display_timezone, Africa/Kampala by default).
 */
final class DisplayTime
{
    public static function zone(): string
    {
        return app(CmsSettings::class)->displayTimezone();
    }

    public static function parse(DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->setTimezone(self::zone());
        } catch (Throwable) {
            return null;
        }
    }

    public static function format(DateTimeInterface|string|null $value, string $format = 'j M Y, H:i'): string
    {
        $time = self::parse($value);

        return $time ? $time->format($format) : '—';
    }

    public static function iso(DateTimeInterface|string|null $value): ?string
    {
        $time = self::parse($value);

        return $time?->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function zoneLabel(): string
    {
        return CarbonImmutable::now(self::zone())->format('T');
    }
}
