<?php

namespace App\Modules\Calendar\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after the work week, a calendar day, or an opened workday changes.
 * Attendance listens and recalculates shifts on these dates (docs/02-attendance-rules.md 3.2.3).
 */
final class CalendarDatesChanged
{
    use Dispatchable;

    /**
     * @param  list<string>  $dates  Y-m-d dates whose workday status may have changed; empty with $allDates = true for work week edits
     * @param  list<int>|null  $userIds  people affected, or null for everyone
     */
    public function __construct(
        public readonly array $dates,
        public readonly ?array $userIds = null,
        public readonly bool $allDates = false,
    ) {}
}
