<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Overtime\Enums\OvertimeStatus;

/**
 * Month numbers for one person, or added up for a team or the studio. Approved, pending and rejected overtime stay
 * separate: only approved overtime counts as paid time (3.4.6). Idle minutes are context, never deducted (3.6.5).
 */
final readonly class Totals
{
    public function __construct(
        public int $people = 0,
        public int $daysWorked = 0,
        public int $regularMinutes = 0,
        public int $overtimeApprovedMinutes = 0,
        public int $overtimePendingMinutes = 0,
        public int $overtimeRejectedMinutes = 0,
        public int $idleMinutes = 0,
        public int $interruptionMinutes = 0,
        public int $shortDays = 0,
        public int $nonWorkdayShifts = 0,
        public int $reviewShifts = 0,
        public int $lateClaims = 0,
        public int $pendingShifts = 0,
        public int $runningShifts = 0,
        public int $leaveDays = 0,
    ) {}

    /**
     * @param  list<ShiftLine>  $lines
     * @param  int  $leaveDays  workdays of approved leave in the month (docs/14 4.3); leave never changes the minutes
     */
    public static function forPerson(array $lines, int $leaveDays = 0): self
    {
        $minutes = fn (OvertimeStatus $status) => array_sum(array_map(
            fn (ShiftLine $l) => $l->overtimeStatus === $status ? $l->overtimeMinutes : 0,
            $lines,
        ));

        $count = fn (callable $test) => count(array_filter($lines, $test));

        return new self(
            people: 1,
            daysWorked: count(array_unique(array_map(fn (ShiftLine $l) => $l->workDate, $lines))),
            regularMinutes: array_sum(array_map(fn (ShiftLine $l) => $l->regularMinutes, $lines)),
            overtimeApprovedMinutes: $minutes(OvertimeStatus::Approved),
            overtimePendingMinutes: $minutes(OvertimeStatus::Pending),
            overtimeRejectedMinutes: $minutes(OvertimeStatus::Rejected),
            idleMinutes: array_sum(array_map(fn (ShiftLine $l) => $l->idleMinutes, $lines)),
            interruptionMinutes: array_sum(array_map(fn (ShiftLine $l) => $l->interruptionMinutes, $lines)),
            // Short is marked on the last shift of a work date, so this counts dates
            shortDays: count(array_unique(array_map(fn (ShiftLine $l) => $l->workDate, array_filter($lines, fn (ShiftLine $l) => $l->isShort)))),
            nonWorkdayShifts: $count(fn (ShiftLine $l) => ! $l->isWorkday),
            reviewShifts: $count(fn (ShiftLine $l) => $l->needsReview()),
            lateClaims: $count(fn (ShiftLine $l) => $l->isLateClaim()),
            pendingShifts: $count(fn (ShiftLine $l) => $l->overtimeStatus === OvertimeStatus::Pending),
            runningShifts: $count(fn (ShiftLine $l) => $l->isRunning()),
            leaveDays: $leaveDays,
        );
    }

    /** @param iterable<Totals> $totals */
    public static function sum(iterable $totals): self
    {
        $sum = array_fill_keys(array_keys(get_object_vars(new self)), 0);

        foreach ($totals as $total) {
            foreach (get_object_vars($total) as $key => $value) {
                $sum[$key] += $value;
            }
        }

        return new self(...$sum);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'people' => $this->people,
            'days_worked' => $this->daysWorked,
            'regular_minutes' => $this->regularMinutes,
            'overtime_approved_minutes' => $this->overtimeApprovedMinutes,
            'overtime_pending_minutes' => $this->overtimePendingMinutes,
            'overtime_rejected_minutes' => $this->overtimeRejectedMinutes,
            'idle_minutes' => $this->idleMinutes,
            'short_days' => $this->shortDays,
            'non_workday_shifts' => $this->nonWorkdayShifts,
            'review_shifts' => $this->reviewShifts,
            'late_claims' => $this->lateClaims,
            'pending_shifts' => $this->pendingShifts,
            'running_shifts' => $this->runningShifts,
            'leave_days' => $this->leaveDays,
        ];
    }
}
