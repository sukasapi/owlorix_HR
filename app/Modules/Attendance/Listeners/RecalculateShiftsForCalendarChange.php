<?php

namespace App\Modules\Attendance\Listeners;

use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftRecalculator;
use App\Modules\Calendar\Events\CalendarDatesChanged;
use Carbon\CarbonImmutable;

/** 3.2.3: opening or closing a date after shifts were recorded recalculates those shifts. */
class RecalculateShiftsForCalendarChange
{
    public function __construct(private readonly ShiftRecalculator $recalculator) {}

    public function handle(CalendarDatesChanged $event): void
    {
        if (! $event->allDates && $event->dates === []) {
            return;
        }

        $now = CarbonImmutable::now();

        Shift::query()
            ->select(['user_id', 'work_date'])
            ->distinct()
            ->when(! $event->allDates, fn ($q) => $q->whereIn('work_date', $event->dates))
            ->when($event->userIds !== null, fn ($q) => $q->whereIn('user_id', $event->userIds))
            ->orderBy('user_id')
            ->orderByDesc('work_date')
            ->get()
            ->each(fn (Shift $row) => $this->recalculator->recalculateWorkDate($row->user_id, $row->work_date, $now, keepSavedRegular: false));
    }
}
