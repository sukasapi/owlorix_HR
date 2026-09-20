<?php

namespace App\Modules\Calendar\Services;

use Carbon\CarbonImmutable;

final class DateLabel
{
    /** "Sabtu, 19 September 2026" in the request locale, for flash and error messages. */
    public static function long(string $date): string
    {
        return CarbonImmutable::parse($date)->locale(app()->getLocale())->translatedFormat('l, j F Y');
    }
}
