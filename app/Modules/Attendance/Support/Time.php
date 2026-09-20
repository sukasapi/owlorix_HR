<?php

namespace App\Modules\Attendance\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Millisecond time helpers. Stored times are UTC `datetime(3)`; query bindings must keep the milliseconds,
 * which Laravel's default binding format drops.
 */
final class Time
{
    public const DB_FORMAT = 'Y-m-d H:i:s.v';

    public static function db(CarbonInterface $time): string
    {
        return $time->copy()->utc()->format(self::DB_FORMAT);
    }

    public static function iso(?CarbonInterface $time): ?string
    {
        return $time?->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function fromMs(int $ms): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampMsUTC($ms);
    }

    public static function parse(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC')->utc();
    }

    /** Studio calendar date (Asia/Jakarta) of a moment. */
    public static function workDate(CarbonInterface $time): string
    {
        return $time->copy()->setTimezone(self::zone())->toDateString();
    }

    public static function zone(): string
    {
        return (string) config('owlorix.display_timezone', 'Asia/Jakarta');
    }
}
