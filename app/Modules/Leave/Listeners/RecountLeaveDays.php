<?php

namespace App\Modules\Leave\Listeners;

use App\Modules\Calendar\Events\CalendarDatesChanged;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveDays;

/**
 * Keeps `days` of pending and approved requests equal to their workdays after the calendar changes (a holiday
 * added, a work week edited, a date opened). Rejected and cancelled requests keep what they had.
 */
class RecountLeaveDays
{
    public function __construct(private readonly LeaveDays $leaveDays) {}

    public function handle(CalendarDatesChanged $event): void
    {
        if (! $event->allDates && $event->dates === []) {
            return;
        }

        $query = LeaveRequest::query()
            ->holding()
            ->with('user')
            ->when($event->userIds !== null, fn ($q) => $q->whereIn('user_id', $event->userIds));

        if (! $event->allDates) {
            $query->where(function ($q) use ($event) {
                foreach ($event->dates as $date) {
                    $q->orWhere(fn ($q) => $q->overlapping($date, $date));
                }
            });
        }

        $query->chunkById(200, function ($requests) {
            foreach ($requests as $request) {
                if ($request->user === null) {
                    continue;
                }

                $days = count($this->leaveDays->workdays($request->user, $request->startDate(), $request->endDate()));

                if ($days !== $request->days) {
                    $request->update(['days' => $days]);
                }
            }
        });
    }
}
