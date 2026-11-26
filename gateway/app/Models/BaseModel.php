<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * UUIDv7 primary keys (time-ordered) and microsecond UTC timestamps, so ordering
 * and lease comparisons are exact. API output uses ISO-8601 UTC.
 */
abstract class BaseModel extends Model
{
    use HasUuids;

    public const DATE_FORMAT = 'Y-m-d H:i:s.u';

    protected $dateFormat = self::DATE_FORMAT;

    protected function serializeDate(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function iso(?DateTimeInterface $date): ?string
    {
        return $date ? CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }

    public static function dbTime(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->utc()->format(self::DATE_FORMAT);
    }
}
