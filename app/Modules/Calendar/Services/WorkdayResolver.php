<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Calendar\Models\WorkWeekDay;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use InvalidArgumentException;

/**
 * Decides whether a date is a workday for a person (docs/02-attendance-rules.md 3.2).
 *
 * Order: a date Management opened for the person or one of their teams is a workday, even on a holiday.
 * Otherwise a calendar exception (holiday, studio day off, studio-wide workday) wins over the default work week.
 * Dates are Asia/Jakarta calendar dates.
 */
class WorkdayResolver
{
    public function isWorkday(User $user, CarbonInterface|string $date): bool
    {
        return $this->verdict($user, $date)->isWorkday;
    }

    public function verdict(User $user, CarbonInterface|string $date): DayVerdict
    {
        $day = $this->toDateString($date);

        return $this->range($user, $day, $day)[$day];
    }

    /**
     * @return array<string, DayVerdict> keyed by Y-m-d, inclusive of both ends
     */
    public function range(User $user, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $start = $this->toDateString($from);
        $end = $this->toDateString($to);

        if ($start > $end) {
            throw new InvalidArgumentException('Range start is after its end.');
        }

        $week = WorkWeekDay::query()->pluck('is_workday', 'weekday')->map(fn ($v) => (bool) $v)->all();

        $calendar = CalendarDay::query()
            ->whereBetween('date', [$start, $end])
            ->get()
            ->keyBy(fn (CalendarDay $d) => $d->date->toDateString());

        $teamIds = $user->teams()->pluck('teams.id')->all();

        $opened = OpenedWorkday::query()
            ->whereBetween('date', [$start, $end])
            ->where(function ($q) use ($user, $teamIds) {
                $q->where(fn ($q) => $q->where('scope_type', OpenedScope::User)->where('scope_id', $user->getKey()));
                if ($teamIds !== []) {
                    $q->orWhere(fn ($q) => $q->where('scope_type', OpenedScope::Team)->whereIn('scope_id', $teamIds));
                }
            })
            ->get()
            ->groupBy(fn (OpenedWorkday $o) => $o->date->toDateString());

        $result = [];

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $key = $date->toDateString();

            if ($opened->has($key)) {
                $result[$key] = new DayVerdict($key, true, DayVerdict::SOURCE_OPENED, label: $opened[$key]->first()->note);

                continue;
            }

            if ($calendar->has($key)) {
                $entry = $calendar[$key];
                $result[$key] = new DayVerdict($key, $entry->type->isWorkday(), DayVerdict::SOURCE_CALENDAR, $entry->type->value, $entry->name);

                continue;
            }

            $result[$key] = new DayVerdict($key, $week[$date->dayOfWeekIso] ?? false, DayVerdict::SOURCE_WORK_WEEK);
        }

        return $result;
    }

    private function toDateString(CarbonInterface|string $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->toDateString();
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new InvalidArgumentException("Invalid date [{$date}].");
        }

        return CarbonImmutable::parse($date)->toDateString();
    }
}
