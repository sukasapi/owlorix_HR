<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Calculation\ShiftCalculator;
use App\Modules\Attendance\Calculation\ShiftEvent;
use App\Modules\Attendance\Calculation\ShiftInput;
use App\Modules\Attendance\Calculation\ShiftResult;
use App\Modules\Attendance\Calculation\ShiftRules;
use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Calendar\Services\WorkdayResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Loads every shift of one person's work date and calculates them in clock-in order, so each shift gets
 * the regular minutes of the earlier shifts on that date (3.3.1). Reads only; ShiftRecalculator saves.
 */
class WorkDateCalculator
{
    public function __construct(
        private readonly ShiftCalculator $calculator,
        private readonly WorkdayResolver $calendar,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array<int, CarbonImmutable>  $nextClockInOverrides  shift id => clock-in of a shift about to be created
     * @param  bool  $keepSavedRegular  false for an explicit recalculation (calendar change, correction)
     * @param  array<int, list<ShiftEvent>>  $extraEvents  shift id => events not saved, for a preview (a correction before it is applied)
     * @return list<ResolvedShift> in clock-in order, cancelled clock-ins included
     */
    public function calculate(int $userId, string $workDate, CarbonImmutable $now, array $nextClockInOverrides = [], bool $keepSavedRegular = true, array $extraEvents = []): array
    {
        $shifts = Shift::query()
            ->where('user_id', $userId)
            ->where('work_date', $workDate)
            ->orderBy('clock_in_at')
            ->orderBy('id')
            ->get()
            ->values();

        if ($shifts->isEmpty()) {
            return [];
        }

        $user = User::withTrashed()->findOrFail($userId);

        /** @var Collection<int, Collection<int, AttendanceEvent>> $events */
        $events = AttendanceEvent::query()->whereIn('shift_id', $shifts->modelKeys())->get()->groupBy('shift_id');

        return $this->resolve(
            $shifts,
            $events,
            $this->calendar->isWorkday($user, $workDate),
            $this->clockInAfter($userId, $shifts->last()),
            $now,
            $nextClockInOverrides,
            $keepSavedRegular,
            $extraEvents,
        );
    }

    /**
     * calculate() for several people on one work date, in a fixed number of queries however many people are asked
     * for (Tim hari ini reloads every 30 s for the whole studio).
     *
     * @param  list<int>  $userIds
     * @return array<int, list<ResolvedShift>> keyed by user id; a person with no shift on the date is left out
     */
    public function calculateMany(array $userIds, string $workDate, CarbonImmutable $now): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var Collection<int, Collection<int, Shift>> $byUser */
        $byUser = Shift::query()
            ->whereIn('user_id', $userIds)
            ->where('work_date', $workDate)
            ->orderBy('clock_in_at')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        if ($byUser->isEmpty()) {
            return [];
        }

        $ids = $byUser->keys()->map(fn ($id) => (int) $id)->all();
        $workdays = $this->calendar->rangeMany($ids, $workDate, $workDate);

        /** @var Collection<int, Collection<int, AttendanceEvent>> $events */
        $events = AttendanceEvent::query()
            ->whereIn('shift_id', $byUser->flatten(1)->map(fn (Shift $shift) => $shift->id)->all())
            ->get()
            ->groupBy('shift_id');

        $lastClockIns = $byUser->map(fn (Collection $shifts) => $shifts->last()->clock_in_at);
        $later = Shift::query()
            ->whereIn('user_id', $ids)
            ->where('clock_in_at', '>', Time::db($lastClockIns->min()))
            ->get(['user_id', 'clock_in_at'])
            ->groupBy('user_id');

        $resolved = [];

        foreach ($byUser as $userId => $shifts) {
            $following = $later->get($userId, collect())
                ->map(fn (Shift $shift) => $shift->clock_in_at)
                ->filter(fn (CarbonImmutable $at) => $at->gt($lastClockIns[$userId]))
                ->min();

            $resolved[(int) $userId] = $this->resolve(
                $shifts->values(),
                $events,
                in_array($workDate, $workdays[(int) $userId] ?? [], true),
                $following?->utc(),
                $now,
            );
        }

        return $resolved;
    }

    /**
     * @param  Collection<int, Shift>  $shifts  one person's shifts on one work date, in clock-in order
     * @param  Collection<int, Collection<int, AttendanceEvent>>  $events  by shift id
     * @param  CarbonImmutable|null  $following  clock-in of the person's first shift after the last of these
     * @return list<ResolvedShift>
     */
    private function resolve(Collection $shifts, Collection $events, bool $isWorkday, ?CarbonImmutable $following, CarbonImmutable $now, array $nextClockInOverrides = [], bool $keepSavedRegular = true, array $extraEvents = []): array
    {
        $rules = ShiftRules::fromSettings($this->settings);

        $clockIns = $shifts->map(fn (Shift $shift) => $this->clockInEvent($shift, $events->get($shift->id, collect())));
        $eventLists = $shifts->map(fn (Shift $shift) => [
            ...$events->get($shift->id, collect())->map->toShiftEvent()->values()->all(),
            ...($extraEvents[$shift->id] ?? []),
        ]);

        $cancelled = $shifts->keys()->map(fn (int $i) => $this->isCancelled($clockIns[$i], $eventLists[$i]))->all();

        /** @var list<array{shift: Shift, result: ShiftResult, before: int}> $calculated */
        $calculated = [];
        $before = 0;

        foreach ($shifts as $i => $shift) {
            $next = $nextClockInOverrides[$shift->id] ?? $this->nextClockIn($shifts, $cancelled, $i) ?? $following;

            $result = $this->calculator->calculate(new ShiftInput(
                clockIn: $clockIns[$i],
                events: $eventLists[$i],
                isWorkday: $isWorkday,
                regularLimitMinutes: $shift->regular_limit_minutes,
                regularBeforeMinutes: $before,
                lastSeenAt: $shift->last_seen_at->utc(),
                now: $now->utc(),
                nextClockInAt: $next,
                rules: $rules,
            ));

            // 3.3.1: once the regular limit of the date was reached and saved, it stays saved whatever happens next.
            // Events that arrive later (an offline sleep or clock-out from before the mark) are recorded but cannot
            // lower it. A calendar change (3.2.3) or a correction (3.10) is an explicit recalculation and may.
            $savedReached = $shift->exists && $shift->is_workday
                && $shift->regular_before_minutes + $shift->regular_minutes >= $shift->regular_limit_minutes;

            if ($keepSavedRegular && $isWorkday && $savedReached && ! $result->cancelled) {
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

            // Short is set on the last shift of the date once it ends below the limit (docs/04 shifts.is_short)
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
