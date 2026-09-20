<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Calculation\ShiftCalculator;
use App\Modules\Attendance\Calculation\ShiftEvent;
use App\Modules\Attendance\Calculation\ShiftInput;
use App\Modules\Attendance\Calculation\ShiftRules;
use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Calendar\Services\DayVerdict;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Riwayat reads a whole month of one person's shifts with a fixed number of queries: the shifts, their events, and
 * the next clock-in after the range. WorkDateCalculator does the same calculation one work date at a time (several
 * queries per date), so this class repeats its steps over data loaded once. HistoryShiftsTest checks that both give
 * identical results; a change to WorkDateCalculator::calculate must be made here too.
 */
class HistoryShifts
{
    public function __construct(
        private readonly ShiftCalculator $calculator,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  string  $from  first work date, Y-m-d
     * @param  string  $to  last work date, Y-m-d
     * @param  array<string, DayVerdict>  $verdicts  the person's calendar for every date in the range
     */
    public function forRange(User $user, string $from, string $to, CarbonImmutable $now, array $verdicts): HistoryShiftSet
    {
        $all = Shift::query()
            ->where('user_id', $user->id)
            ->whereBetween('work_date', [$from, $to])
            ->orderBy('clock_in_at')
            ->orderBy('id')
            ->get()
            ->values();

        if ($all->isEmpty()) {
            return new HistoryShiftSet([], []);
        }

        /** @var Collection<int, Collection<int, AttendanceEvent>> $events */
        $events = AttendanceEvent::query()->whereIn('shift_id', $all->modelKeys())->get()->groupBy('shift_id');
        $lists = [];

        foreach ($all as $shift) {
            $lists[$shift->id] = $events->get($shift->id, collect())->map->toShiftEvent()->values()->all();
        }

        $rules = ShiftRules::fromSettings($this->settings);
        $afterRange = $this->clockInAfter($user->id, $all->last());
        $byDate = [];

        foreach ($all->groupBy('work_date') as $date => $shifts) {
            // The next clock-in after the date's last shift: a later date in the range, or the first one after it
            $last = $shifts->last()->clock_in_at;
            $following = $all->first(fn (Shift $s) => $s->clock_in_at->greaterThan($last))?->clock_in_at->utc() ?? $afterRange;
            $kept = array_values(array_filter(
                $this->calculateDate($shifts->values(), $events, $lists, $verdicts[$date]->isWorkday, $rules, $now, $following),
                fn (ResolvedShift $resolved) => ! $resolved->result->cancelled,
            ));

            if ($kept !== []) {
                $byDate[$date] = $kept;
            }
        }

        return new HistoryShiftSet($byDate, $lists);
    }

    /**
     * WorkDateCalculator::calculate for one date, on loaded data.
     *
     * @param  Collection<int, Shift>  $shifts  in clock-in order
     * @param  Collection<int, Collection<int, AttendanceEvent>>  $events
     * @param  array<int, list<ShiftEvent>>  $lists  the same events per shift id, ready for the calculator
     * @return list<ResolvedShift>
     */
    private function calculateDate(Collection $shifts, Collection $events, array $lists, bool $isWorkday, ShiftRules $rules, CarbonImmutable $now, ?CarbonImmutable $following): array
    {
        $clockIns = $shifts->map(fn (Shift $shift) => $this->clockInEvent($shift, $events->get($shift->id, collect())));
        $eventLists = $shifts->map(fn (Shift $shift) => $lists[$shift->id]);
        $cancelled = $shifts->keys()->map(fn (int $i) => $this->isCancelled($clockIns[$i], $eventLists[$i]))->all();

        $calculated = [];
        $before = 0;

        foreach ($shifts as $i => $shift) {
            $result = $this->calculator->calculate(new ShiftInput(
                clockIn: $clockIns[$i],
                events: $eventLists[$i],
                isWorkday: $isWorkday,
                regularLimitMinutes: $shift->regular_limit_minutes,
                regularBeforeMinutes: $before,
                lastSeenAt: $shift->last_seen_at->utc(),
                now: $now->utc(),
                nextClockInAt: $this->nextClockIn($shifts, $cancelled, $i) ?? $following,
                rules: $rules,
            ));

            // 3.3.1: regular time saved at the limit stays saved (WorkDateCalculator, I9)
            $savedReached = $shift->is_workday
                && $shift->regular_before_minutes + $shift->regular_minutes >= $shift->regular_limit_minutes;

            if ($isWorkday && $savedReached && ! $result->cancelled) {
                $floor = max(0, $shift->regular_limit_minutes - $before);

                if ($result->regularMinutes < $floor) {
                    $result = $result->withRegularMinutes($floor);
                }
            }

            $calculated[] = ['shift' => $shift, 'result' => $result, 'before' => $before];

            if (! $result->cancelled) {
                $before += $result->regularMinutes;
            }
        }

        $lastKept = null;

        foreach ($calculated as $i => $row) {
            if (! $row['result']->cancelled) {
                $lastKept = $i;
            }
        }

        return array_map(function (int $i) use ($calculated, $lastKept, $isWorkday, $before) {
            $row = $calculated[$i];
            $result = $row['result'];

            $isShort = $i === $lastKept
                && $isWorkday
                && $result->clockOutAt !== null
                && $before < $row['shift']->regular_limit_minutes;

            return new ResolvedShift($row['shift'], $result, $row['before'], $isWorkday, $isShort);
        }, array_keys($calculated));
    }

    /** @param Collection<int, AttendanceEvent> $events */
    private function clockInEvent(Shift $shift, Collection $events): ShiftEvent
    {
        $event = $events->first(fn (AttendanceEvent $e) => $e->type === EventType::ClockIn);

        return $event?->toShiftEvent() ?? new ShiftEvent('shift-'.$shift->id, EventType::ClockIn, $shift->clock_in_at->utc());
    }

    /** @param list<ShiftEvent> $events */
    private function isCancelled(ShiftEvent $clockIn, array $events): bool
    {
        foreach ($events as $event) {
            $elapsed = $event->ms() - $clockIn->ms();

            if ($event->type === EventType::ClockInCancelled && $elapsed >= 0 && $elapsed <= ShiftRules::CANCEL_WINDOW_SECONDS * 1000) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @param  array<int, bool>  $cancelled
     */
    private function nextClockIn(Collection $shifts, array $cancelled, int $index): ?CarbonImmutable
    {
        for ($i = $index + 1; $i < $shifts->count(); $i++) {
            if (! $cancelled[$i]) {
                return $shifts[$i]->clock_in_at->utc();
            }
        }

        return null;
    }

    private function clockInAfter(int $userId, Shift $shift): ?CarbonImmutable
    {
        $value = Shift::query()
            ->where('user_id', $userId)
            ->where('clock_in_at', '>', Time::db($shift->clock_in_at))
            ->min('clock_in_at');

        return $value !== null ? Time::parse((string) $value) : null;
    }
}
