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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /** Days in the default work week (Monday to Friday is 5), before holidays and opened dates. */
    public function workWeekLength(): int
    {
        return WorkWeekDay::query()->where('is_workday', true)->count();
    }

    /**
     * @return array<string, DayVerdict> keyed by Y-m-d, inclusive of both ends
     */
    public function range(User $user, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        return $this->resolve([(int) $user->getKey()], $from, $to)[(int) $user->getKey()];
    }

    /**
     * The workdays of several people at once, with the same reading as range() per person but a fixed number of
     * queries however many people are asked for.
     *
     * @param  Collection<int, User>|array<int, User|int>  $users  models or ids
     * @return array<int, list<string>> every requested id is a key; Y-m-d workdays in date order
     */
    public function rangeMany(Collection|array $users, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $ids = collect($users)->map(fn (User|int $u) => $u instanceof User ? (int) $u->getKey() : (int) $u)->unique()->values()->all();

        return array_map(
            fn (array $verdicts) => array_keys(array_filter($verdicts, fn (DayVerdict $v) => $v->isWorkday)),
            $this->resolve($ids, $from, $to),
        );
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array<string, DayVerdict>>
     */
    private function resolve(array $userIds, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $start = $this->toDateString($from);
        $end = $this->toDateString($to);

        if ($start > $end) {
            throw new InvalidArgumentException('Range start is after its end.');
        }

        if ($userIds === []) {
            return [];
        }

        $week = WorkWeekDay::query()->pluck('is_workday', 'weekday')->map(fn ($v) => (bool) $v)->all();

        $calendar = CalendarDay::query()
            ->whereBetween('date', [$start, $end])
            ->get()
            ->keyBy(fn (CalendarDay $d) => $d->date->toDateString());

        $memberships = DB::table('team_user')
            ->join('teams', 'teams.id', '=', 'team_user.team_id')
            ->whereIn('team_user.user_id', $userIds)
            ->get(['team_user.user_id', 'team_user.team_id']);
        $teamsOf = [];

        foreach ($memberships as $row) {
            $teamsOf[(int) $row->user_id][(int) $row->team_id] = true;
        }

        $teamIds = $memberships->pluck('team_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $opened = OpenedWorkday::query()
            ->whereBetween('date', [$start, $end])
            ->where(function ($q) use ($userIds, $teamIds) {
                $q->where(fn ($q) => $q->where('scope_type', OpenedScope::User)->whereIn('scope_id', $userIds));
                if ($teamIds !== []) {
                    $q->orWhere(fn ($q) => $q->where('scope_type', OpenedScope::Team)->whereIn('scope_id', $teamIds));
                }
            })
            ->orderBy('id')
            ->get()
            ->groupBy(fn (OpenedWorkday $o) => $o->date->toDateString());

        $dates = [];

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $dates[$date->toDateString()] = $date->dayOfWeekIso;
        }

        $result = [];

        foreach ($userIds as $userId) {
            $teams = $teamsOf[$userId] ?? [];

            foreach ($dates as $key => $weekday) {
                $open = $opened->has($key) ? $opened[$key]->first(fn (OpenedWorkday $o) => $o->scope_type === OpenedScope::User
                    ? (int) $o->scope_id === $userId
                    : isset($teams[(int) $o->scope_id])) : null;

                if ($open !== null) {
                    $result[$userId][$key] = new DayVerdict($key, true, DayVerdict::SOURCE_OPENED, label: $open->note);

                    continue;
                }

                if ($calendar->has($key)) {
                    $entry = $calendar[$key];
                    $result[$userId][$key] = new DayVerdict($key, $entry->type->isWorkday(), DayVerdict::SOURCE_CALENDAR, $entry->type->value, $entry->name);

                    continue;
                }

                $result[$userId][$key] = new DayVerdict($key, $week[$weekday] ?? false, DayVerdict::SOURCE_WORK_WEEK);
            }
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
