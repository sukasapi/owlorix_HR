<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Calculation\IdlePeriodResult;
use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Events\ShiftRecalculated;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\IdlePeriod;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Saves shifts calculated from their events. Always recalculates a whole work date, so a change to one shift
 * carries regular_before_minutes to the later shifts of that date.
 */
class ShiftRecalculator
{
    public function __construct(
        private readonly WorkDateCalculator $dates,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  array<int, CarbonImmutable>  $nextClockInOverrides
     * @param  bool  $keepSavedRegular  false for an explicit recalculation (calendar change, correction), see WorkDateCalculator
     * @return list<ResolvedShift> the saved shifts; cancelled clock-ins are removed
     */
    public function recalculateWorkDate(int $userId, string $workDate, ?CarbonImmutable $now = null, array $nextClockInOverrides = [], bool $keepSavedRegular = true): array
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($userId, $workDate, $now, $nextClockInOverrides, $keepSavedRegular) {
            $resolved = $this->dates->calculate($userId, $workDate, $now, $nextClockInOverrides, $keepSavedRegular);
            $cancelled = array_filter($resolved, fn (ResolvedShift $r) => $r->result->cancelled);

            if ($cancelled !== []) {
                // Remove undone clock-ins first, then calculate again as if they never happened
                $otherDates = [];

                foreach ($cancelled as $undone) {
                    $previous = $this->removeCancelled($undone->shift);

                    if ($previous !== null && $previous->work_date !== $workDate) {
                        $otherDates[$previous->work_date] = true;
                    }
                }

                $saved = $this->recalculateWorkDate($userId, $workDate, $now, $nextClockInOverrides, $keepSavedRegular);

                foreach (array_keys($otherDates) as $date) {
                    $this->recalculateWorkDate($userId, $date, $now, [], $keepSavedRegular);
                }

                return $saved;
            }

            // Only one shift per person may be saved without a clock-out (unique open_user_id), so shifts that end
            // are saved before the one that is still running
            $ordered = [
                ...array_filter($resolved, fn (ResolvedShift $r) => ! $r->result->isLive()),
                ...array_filter($resolved, fn (ResolvedShift $r) => $r->result->isLive()),
            ];

            foreach ($ordered as $shift) {
                $this->save($shift);
            }

            return $resolved;
        });
    }

    /**
     * 3.1.2: an undone clock-in is out of the totals. Its own clock-in and undo stay in the log without a shift;
     * other events recorded meanwhile go back to the shift that was running before it. A correction names its own
     * shift and never moves to another one.
     */
    private function removeCancelled(Shift $shift): ?Shift
    {
        $previous = Shift::query()
            ->where('user_id', $shift->user_id)
            ->whereKeyNot($shift->id)
            ->where('clock_in_at', '<', Time::db($shift->clock_in_at))
            ->orderByDesc('clock_in_at')
            ->orderByDesc('id')
            ->first();

        AttendanceEvent::query()
            ->where('shift_id', $shift->id)
            ->whereNotIn('type', [EventType::ClockIn->value, EventType::ClockInCancelled->value, EventType::CorrectionApplied->value])
            ->update(['shift_id' => $previous?->id]);

        $shift->delete();

        return $previous;
    }

    /** @param iterable<int> $shiftIds */
    public function recalculateShifts(iterable $shiftIds, ?CarbonImmutable $now = null): void
    {
        $ids = collect($shiftIds)->unique()->values()->all();

        if ($ids === []) {
            return;
        }

        // Latest dates first: a cancelled clock-in is removed before an earlier shift looks for the next clock-in
        Shift::query()
            ->whereIn('id', $ids)
            ->get(['user_id', 'work_date'])
            ->unique(fn (Shift $s) => $s->user_id.'|'.$s->work_date)
            ->sortByDesc('work_date')
            ->each(fn (Shift $s) => $this->recalculateWorkDate($s->user_id, $s->work_date, $now));
    }

    private function save(ResolvedShift $resolved): void
    {
        $shift = $resolved->shift;
        $r = $resolved->result;

        $shift->forceFill([
            // Differs from the saved value only after a clock-in correction (3.10)
            'clock_in_at' => $r->clockInAt,
            'is_workday' => $resolved->isWorkday,
            'regular_before_minutes' => $resolved->regularBeforeMinutes,
            'regular_ends_at' => $r->regularEndsAt,
            'clock_out_at' => $r->clockOutAt,
            'last_seen_at' => $r->lastSeenAt->greaterThan($shift->last_seen_at) ? $r->lastSeenAt : $shift->last_seen_at,
            'status' => $r->status,
            'end_reason' => $r->endReason,
            'overtime_end_reason' => $r->overtimeEndReason,
            'is_short' => $resolved->isShort,
            'regular_minutes' => $r->regularMinutes,
            'overtime_minutes' => $r->overtimeMinutes,
            'idle_minutes' => $r->idleMinutes,
            'interruption_minutes' => $r->interruptionMinutes,
            'flags' => array_map(fn ($flag) => $flag->value, $r->flags),
        ])->save();

        $this->syncIdlePeriods($shift, $r->idlePeriods);

        $this->events->dispatch(new ShiftRecalculated($shift, $r));
    }

    /** @param list<IdlePeriodResult> $periods */
    private function syncIdlePeriods(Shift $shift, array $periods): void
    {
        $wanted = array_map(fn (IdlePeriodResult $p) => [
            'started_at' => Time::db($p->startedAt),
            'ended_at' => $p->endedAt !== null ? Time::db($p->endedAt) : null,
            'minutes' => $p->minutes,
            'tag' => $p->tag?->value,
            'note' => $p->note,
        ], $periods);

        $current = IdlePeriod::query()->where('shift_id', $shift->id)->orderBy('started_at')->orderBy('id')->get()
            ->map(fn (IdlePeriod $p) => [
                'started_at' => Time::db($p->started_at),
                'ended_at' => $p->ended_at !== null ? Time::db($p->ended_at) : null,
                'minutes' => $p->minutes,
                'tag' => $p->tag?->value,
                'note' => $p->note,
            ])
            ->all();

        if ($current === $wanted) {
            return;
        }

        IdlePeriod::query()->where('shift_id', $shift->id)->delete();

        if ($wanted !== []) {
            IdlePeriod::query()->insert(array_map(fn (array $row) => $row + ['shift_id' => $shift->id], $wanted));
        }
    }
}
