<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Calendar\Services\WorkdayResolver;
use App\Modules\Identity\Enums\EmploymentType;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Services\LeaveDays;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Weekly work target per employment type (docs/02 3.12). Display only: it never changes regular time, overtime, or
 * the 8-hour mark.
 *
 * - Permanent and contract: `target.weekly_hours` of regular time per studio week (Monday to Sunday). Each default
 *   workday is worth weekly / work-week length, so a holiday or approved leave on a workday lowers the target by one
 *   day; an extra opened workday never raises it past the weekly figure.
 * - Intern: a number of days present per week times hours per day, per person or the Superadmin default. Fewer
 *   workdays left in the week (holidays, leave) lower the days asked for.
 * - Freelance: no target.
 *
 * Worked time is regular minutes only: overtime is asked for and paid separately, so it does not fill the target.
 */
class WeekTarget
{
    public const KIND_HOURS = 'hours';

    public const KIND_INTERN = 'intern';

    public function __construct(
        private readonly WorkdayResolver $workdays,
        private readonly LeaveDays $leaveDays,
        private readonly Settings $settings,
        private readonly ShiftStateResolver $resolver,
    ) {}

    /** The Monday (studio zone) of the week holding a Y-m-d work date. */
    public static function mondayOf(string $date): CarbonImmutable
    {
        $day = CarbonImmutable::parse($date, Time::zone())->startOfDay();

        return $day->subDays($day->dayOfWeekIso - 1);
    }

    /**
     * What is asked of the person each week, before holidays and leave. Null for a type without a target.
     *
     * @return array{kind: string, weekly_minutes: int|null, days: int|null, minutes_per_day: int|null}|null
     */
    public function rule(User $user): ?array
    {
        return match ($user->employment_type) {
            EmploymentType::Permanent, EmploymentType::Contract => [
                'kind' => self::KIND_HOURS,
                'weekly_minutes' => $this->settings->int('target.weekly_hours') * 60,
                'days' => null,
                'minutes_per_day' => null,
            ],
            EmploymentType::Intern => [
                'kind' => self::KIND_INTERN,
                'weekly_minutes' => null,
                'days' => $user->intern_days_per_week ?? $this->settings->int('target.intern_days_per_week'),
                'minutes_per_day' => $user->intern_minutes_per_day ?? $this->settings->int('target.intern_minutes_per_day'),
            ],
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    public function forUser(User $user, CarbonImmutable $monday, ?CarbonImmutable $now = null): ?array
    {
        return $this->forMany(collect([$user]), $monday, $now)[$user->id];
    }

    /**
     * Several weeks of one person (Riwayat), read in one pass: the query count does not grow with the weeks.
     *
     * @param  list<CarbonImmutable>  $mondays
     * @return list<array<string, mixed>> in the order given; empty for a type without a target
     */
    public function weeksOf(User $user, array $mondays, ?CarbonImmutable $now = null): array
    {
        $weeks = $this->compute(collect([$user]), $mondays, $now ?? CarbonImmutable::now())[$user->id] ?? [];

        return array_values($weeks);
    }

    /**
     * The week of every person, in a fixed number of queries plus one live calculation per running shift.
     * Keys per person: kind, week_start, week_end, full_target_minutes (before holidays and leave), target_minutes,
     * worked_minutes, short_minutes, available_days, leave_days, attended_days, target_days and minutes_per_day
     * (interns only).
     *
     * @param  Collection<int, User>  $people
     * @return array<int, array<string, mixed>|null> every person is a key; null for a type without a target
     */
    public function forMany(Collection $people, CarbonImmutable $monday, ?CarbonImmutable $now = null): array
    {
        $computed = $this->compute($people, [$monday], $now ?? CarbonImmutable::now());

        return $people->mapWithKeys(fn (User $u) => [$u->id => isset($computed[$u->id]) ? reset($computed[$u->id]) : null])->all();
    }

    /**
     * @param  Collection<int, User>  $people
     * @param  list<CarbonImmutable>  $mondays
     * @return array<int, array<string, array<string, mixed>>> only people with a target; weeks keyed by their Monday
     */
    private function compute(Collection $people, array $mondays, CarbonImmutable $now): array
    {
        $rules = array_filter($people->mapWithKeys(fn (User $u) => [$u->id => $this->rule($u)])->all());
        $ids = array_keys($rules);
        $weeks = array_map(fn (CarbonImmutable $m) => $m->setTimezone(Time::zone())->startOfDay(), $mondays);

        if ($ids === [] || $weeks === []) {
            return [];
        }

        $from = min(array_map(fn (CarbonImmutable $m) => $m->toDateString(), $weeks));
        $until = max(array_map(fn (CarbonImmutable $m) => $m->addDays(6)->toDateString(), $weeks));

        $calendar = $this->workdays->rangeMany($ids, $from, $until);
        $leave = $this->leaveDays->approvedDates($ids, $from, $until);
        $worked = $this->worked($ids, $from, $until, $now);
        $weekLength = max(1, $this->workdays->workWeekLength());
        $result = [];

        foreach ($ids as $id) {
            foreach ($weeks as $monday) {
                $start = $monday->toDateString();
                $end = $monday->addDays(6)->toDateString();
                $inWeek = fn (string $date) => $date >= $start && $date <= $end;
                $rule = $rules[$id];

                $workdays = array_filter($calendar[$id] ?? [], $inWeek);
                $leaveDays = count(array_intersect($workdays, $leave[$id] ?? []));
                $available = count($workdays) - $leaveDays;
                $days = array_filter($worked[$id] ?? [], $inWeek, ARRAY_FILTER_USE_KEY);
                $minutes = array_sum($days);

                if ($rule['kind'] === self::KIND_HOURS) {
                    $full = $rule['weekly_minutes'];
                    $target = min($full, intdiv($full, $weekLength) * $available);
                    $targetDays = null;
                } else {
                    $full = $rule['days'] * $rule['minutes_per_day'];
                    $targetDays = min($rule['days'], $available);
                    $target = $targetDays * $rule['minutes_per_day'];
                }

                $result[$id][$start] = [
                    'kind' => $rule['kind'],
                    'week_start' => $start,
                    'week_end' => $end,
                    'full_target_minutes' => $full,
                    'target_minutes' => $target,
                    'worked_minutes' => $minutes,
                    'short_minutes' => max(0, $target - $minutes),
                    'available_days' => $available,
                    'leave_days' => $leaveDays,
                    'attended_days' => count($days),
                    'target_days' => $targetDays,
                    'minutes_per_day' => $rule['minutes_per_day'],
                ];
            }
        }

        return $result;
    }

    /**
     * Regular minutes per person and work date that has a shift. Saved rows are used as they are; a date with a
     * running shift is worked out now, since its saved minutes trail behind until the next sync.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, int>>
     */
    private function worked(array $ids, string $from, string $until, CarbonImmutable $now): array
    {
        $worked = [];

        DB::table('shifts')
            ->whereIn('user_id', $ids)
            ->whereBetween('work_date', [$from, $until])
            ->groupBy('user_id', 'work_date')
            ->selectRaw('user_id, work_date, sum(regular_minutes) as minutes')
            ->get()
            ->each(function (object $row) use (&$worked) {
                $worked[(int) $row->user_id][substr((string) $row->work_date, 0, 10)] = (int) $row->minutes;
            });

        $running = Shift::query()
            ->whereIn('user_id', $ids)
            ->whereNull('clock_out_at')
            ->whereBetween('work_date', [$from, $until])
            ->get(['user_id', 'work_date']);

        foreach ($running as $shift) {
            $date = (string) $shift->work_date;
            $resolved = $this->resolver->workDate((int) $shift->user_id, $date, $now);

            if ($resolved === []) {
                unset($worked[(int) $shift->user_id][$date]);

                continue;
            }

            $worked[(int) $shift->user_id][$date] = array_sum(array_map(fn (ResolvedShift $r) => $r->result->regularMinutes, $resolved));
        }

        return $worked;
    }
}
