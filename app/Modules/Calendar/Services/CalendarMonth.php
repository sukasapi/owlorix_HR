<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Calendar\Models\WorkWeekDay;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Props for the Kalender page: one month of studio dates (Asia/Jakarta) with the default work week,
 * calendar entries, and dates opened for teams or people.
 */
class CalendarMonth
{
    public function __construct(private readonly WorkdayOpening $opening) {}

    /** Parses `YYYY-MM`; anything else falls back to the current studio month. */
    public function resolveMonth(?string $value, CarbonImmutable $today): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $m) && (int) $m[1] >= 1900 && (int) $m[1] <= 2999) {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, 'UTC');
        }

        return CarbonImmutable::create($today->year, $today->month, 1, 0, 0, 0, 'UTC');
    }

    /** @return array<string, mixed> */
    public function build(User $viewer, CarbonImmutable $month, CarbonImmutable $today): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth()->startOfDay();
        $todayString = $today->toDateString();
        $rights = $this->opening->rightsFor($viewer);

        $week = WorkWeekDay::query()->orderBy('weekday')->pluck('is_workday', 'weekday')->map(fn ($v) => (bool) $v);

        $entries = CalendarDay::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (CalendarDay $d) => $d->date->toDateString());

        $opened = OpenedWorkday::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $teamNames = Team::query()
            ->whereIn('id', $opened->where('scope_type', OpenedScope::Team)->pluck('scope_id')->unique())
            ->pluck('name', 'id');

        $userNames = User::withTrashed()
            ->whereIn('id', $opened->where('scope_type', OpenedScope::User)->pluck('scope_id')->merge($opened->pluck('opened_by'))->unique())
            ->pluck('name', 'id');

        $openedByDate = $opened->groupBy(fn (OpenedWorkday $o) => $o->date->toDateString());

        $days = [];

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $key = $date->toDateString();
            $weekWorkday = $week[$date->dayOfWeekIso] ?? false;
            $entry = $entries->get($key);

            $days[] = [
                'date' => $key,
                'weekday' => $date->dayOfWeekIso,
                'is_today' => $key === $todayString,
                'is_past' => $key < $todayString,
                'week_workday' => $weekWorkday,
                'is_studio_workday' => $entry ? $entry->type->isWorkday() : $weekWorkday,
                'entry' => $entry ? [
                    'id' => $entry->id,
                    'type' => $entry->type->value,
                    'name' => $entry->name,
                ] : null,
                'opened' => $openedByDate->get($key, collect())->map(fn (OpenedWorkday $o) => [
                    'id' => $o->id,
                    'scope_type' => $o->scope_type->value,
                    'scope_id' => (int) $o->scope_id,
                    'scope_name' => $o->scope_type === OpenedScope::Team
                        ? ($teamNames[$o->scope_id] ?? __('calendar::messages.deleted_team'))
                        : ($userNames[$o->scope_id] ?? __('calendar::messages.deleted_person')),
                    'opened_by' => $userNames[$o->opened_by] ?? __('calendar::messages.deleted_person'),
                    'note' => $o->note,
                    'can_close' => $rights->permits($o->scope_type, (int) $o->scope_id),
                ])->values()->all(),
            ];
        }

        return [
            'month' => [
                'value' => $start->format('Y-m'),
                'previous' => $start->subMonthNoOverflow()->format('Y-m'),
                'next' => $start->addMonthNoOverflow()->format('Y-m'),
                'current' => $today->format('Y-m'),
            ],
            'today' => $todayString,
            'work_week' => $week->map(fn (bool $isWorkday, int $weekday) => ['weekday' => $weekday, 'is_workday' => $isWorkday])->values()->all(),
            'days' => $days,
            'can' => [
                'manage_calendar' => $viewer->hasPermission(Permission::ManageCalendar),
                'open_workdays' => $viewer->hasPermission(Permission::ManageCalendar) || $viewer->hasPermission(Permission::OpenWorkdays),
            ],
            'scopes' => [
                'teams' => $this->opening->teamsFor($viewer)->map(fn (Team $team) => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'member_count' => (int) $team->members_count,
                ])->values()->all(),
                'people' => $this->opening->peopleFor($viewer)->map(fn (User $person) => [
                    'id' => $person->id,
                    'name' => $person->name,
                    'username' => $person->username,
                ])->values()->all(),
            ],
        ];
    }
}
