<?php

namespace App\Modules\Leave\Services;

use App\Modules\Calendar\Services\WorkdayResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;

/**
 * Which dates a leave request covers. Only workdays for the person count (Calendar\Services\WorkdayResolver), so
 * weekends and holidays never use up leave, and a date the studio opened for them does (docs/14 4.2).
 *
 * `approvedDates` is the contract other modules read (Team today, reports, workload): approved leave only.
 */
class LeaveDays
{
    public function __construct(private readonly WorkdayResolver $resolver) {}

    /** @return list<string> Y-m-d workdays of the person between both dates, inclusive */
    public function workdays(User $user, string $from, string $until): array
    {
        if ($from > $until) {
            return [];
        }

        $days = [];

        foreach ($this->resolver->range($user, $from, $until) as $date => $verdict) {
            if ($verdict->isWorkday) {
                $days[] = $date;
            }
        }

        return $days;
    }

    /**
     * Workdays with approved leave, per person, cut to [from, until].
     *
     * @param  list<int>  $userIds
     * @return array<int, list<string>> every requested id is a key; the dates are sorted Y-m-d strings
     */
    public function approvedDates(array $userIds, string $from, string $until): array
    {
        return array_map(
            fn (array $dates) => array_keys($dates),
            $this->approvedByDate($userIds, $from, $until),
        );
    }

    /**
     * Approved leave on one date, for people whose workday it is, with the type to show (Team today).
     *
     * @param  list<int>  $userIds
     * @return array<int, array{request_id: int, type: string}> only people on leave that date
     */
    public function onDate(array $userIds, string $date): array
    {
        $found = [];

        foreach ($this->approvedByDate($userIds, $date, $date) as $userId => $dates) {
            if (isset($dates[$date])) {
                $found[$userId] = $dates[$date];
            }
        }

        return $found;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array<string, array{request_id: int, type: string}>> keyed by user id, then by sorted date
     */
    private function approvedByDate(array $userIds, string $from, string $until): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        $result = array_fill_keys($ids, []);

        if ($ids === [] || $from > $until) {
            return $result;
        }

        $requests = LeaveRequest::query()
            ->where('status', LeaveStatus::Approved)
            ->whereIn('user_id', $ids)
            ->overlapping($from, $until)
            ->with('type:id,name')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        if ($requests->isEmpty()) {
            return $result;
        }

        // One calendar read for everyone over the span their requests touch
        $start = max($from, $requests->min(fn (LeaveRequest $r) => $r->startDate()));
        $end = min($until, $requests->max(fn (LeaveRequest $r) => $r->endDate()));
        $byUser = $requests->groupBy('user_id');
        $workdays = $this->resolver->rangeMany($byUser->keys()->all(), $start, $end);

        foreach ($byUser as $userId => $own) {
            foreach ($workdays[$userId] ?? [] as $date) {
                $request = $own->first(fn (LeaveRequest $r) => $date >= $r->startDate() && $date <= $r->endDate());

                if ($request !== null) {
                    $result[$userId][$date] = ['request_id' => $request->id, 'type' => $request->type?->name ?? ''];
                }
            }
        }

        return $result;
    }
}
