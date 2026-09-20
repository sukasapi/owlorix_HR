<?php

namespace App\Modules\Calendar\Enums;

enum CalendarDayType: string
{
    case Holiday = 'holiday';
    case StudioDayOff = 'studio_day_off';
    case Workday = 'workday';

    public function isWorkday(): bool
    {
        return $this === self::Workday;
    }
}
