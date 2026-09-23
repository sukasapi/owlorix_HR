<?php

namespace App\Modules\Monitoring\Services;

use App\Modules\Attendance\Support\Time;
use App\Modules\Calendar\Services\WorkdayResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Services\LeaveDays;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Services\TaskPresenter;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Beban kerja (docs/14 5.2): one studio week (Monday to Sunday) per person in the viewer's WorkScope.
 *
 * Capacity: the person's workdays that week (Calendar), minus approved leave days, times the regular day limit.
 * Planned: what is left of the estimates of open tasks assigned to them that are due that week or already overdue.
 * Left = estimate minus timer minutes on the task, never below 0. "Open" here means the assignee can still work on
 * it (todo, in progress, changes requested); a task in review waits for the lead, not for them.
 */
class Workload
{
    /** Planned share of capacity: below LOOSE is "longgar", from FULL up to 100 percent "penuh", past it "lebih". */
    public const LOOSE = 0.5;

    public const FULL = 0.8;

    /** Sort order: whoever is overloaded first. */
    public const STATUSES = ['over', 'full', 'fit', 'loose', 'no_capacity'];

    public function __construct(
        private readonly WorkScope $scope,
        private readonly WorkdayResolver $workdays,
        private readonly LeaveDays $leaveDays,
        private readonly Settings $settings,
    ) {}

    /** The Monday of the week holding $date (studio date), or of this week when $date is not a valid Y-m-d. */
    public static function weekStart(?string $date): CarbonImmutable
    {
        $zone = Time::zone();
        $day = CarbonImmutable::now($zone)->startOfDay();

        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $day = CarbonImmutable::parse($date, $zone)->startOfDay();
        }

        return $day->subDays($day->dayOfWeekIso - 1);
    }

    public static function status(int $planned, int $capacity): string
    {
        if ($capacity <= 0) {
            return $planned > 0 ? 'over' : 'no_capacity';
        }

        $ratio = $planned / $capacity;

        return match (true) {
            $ratio > 1 => 'over',
            $ratio >= self::FULL => 'full',
            $ratio >= self::LOOSE => 'fit',
            default => 'loose',
        };
    }

    /** @return array<string, mixed> */
    public function build(User $viewer, CarbonImmutable $monday): array
    {
        $sunday = $monday->addDays(6);
        $thisWeek = self::weekStart(null);
        $limit = $this->settings->int('attendance.regular_limit_minutes');
        $range = [Time::db($monday), Time::db($monday->addWeek())];

        $people = $this->scope->peopleQuery($viewer)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'nickname', 'username', 'avatar_path']);
        $ids = $people->modelKeys();

        $calendar = $this->workdays->rangeMany($ids, $monday, $sunday);
        $leave = $this->leaveDays->approvedDates($ids, $monday->toDateString(), $sunday->toDateString());
        $planned = $this->planned($ids, $sunday->toDateString());
        $logged = $this->logged($ids, $range);
        $regular = $ids === [] ? collect() : DB::table('shifts')
            ->whereIn('user_id', $ids)
            ->whereBetween('work_date', [$monday->toDateString(), $sunday->toDateString()])
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(regular_minutes) as minutes')
            ->pluck('minutes', 'user_id');

        $rows = $people->map(function (User $person) use ($calendar, $leave, $planned, $logged, $regular, $limit) {
            $workdays = $calendar[$person->id] ?? [];
            $leaveDays = array_values(array_intersect($workdays, $leave[$person->id] ?? []));
            $days = count($workdays) - count($leaveDays);
            $capacity = $days * $limit;
            $plan = $planned[$person->id] ?? ['minutes' => 0, 'tasks' => 0, 'without_estimate' => 0];

            return [
                'person' => TaskPresenter::person($person),
                'workdays' => count($workdays),
                'leave_days' => count($leaveDays),
                'capacity_minutes' => $capacity,
                'planned_minutes' => $plan['minutes'],
                'planned_tasks' => $plan['tasks'],
                'without_estimate' => $plan['without_estimate'],
                'logged_minutes' => (int) ($logged[$person->id] ?? 0),
                'regular_minutes' => (int) ($regular[$person->id] ?? 0),
                'status' => self::status($plan['minutes'], $capacity),
            ];
        });

        $sorted = $rows
            ->sortBy(fn (array $r) => [
                array_search($r['status'], self::STATUSES, true),
                -($r['capacity_minutes'] > 0 ? $r['planned_minutes'] / $r['capacity_minutes'] : $r['planned_minutes']),
            ])
            ->values();

        return [
            'week' => [
                'start' => $monday->toDateString(),
                'end' => $sunday->toDateString(),
                'previous' => $monday->subWeek()->toDateString(),
                'next' => $monday->addWeek()->toDateString(),
                'current' => $thisWeek->toDateString(),
                'is_current' => $monday->equalTo($thisWeek),
            ],
            'limit_minutes' => $limit,
            'thresholds' => ['loose' => self::LOOSE, 'full' => self::FULL],
            'scope' => [
                'studio' => $this->scope->isStudio($viewer),
                'empty_reason' => $this->scope->workloadEmptyReason($viewer),
                'leads_team' => $this->scope->leadsTeam($viewer),
            ],
            'counts' => collect(self::STATUSES)->mapWithKeys(fn (string $s) => [$s => $sorted->where('status', $s)->count()])->all(),
            'people' => $sorted->all(),
        ];
    }

    /**
     * Left-over estimate per assignee of workable tasks due by $until (so this week or overdue), in one grouped query.
     *
     * @param  list<int>  $ids
     * @return array<int, array{minutes: int, tasks: int, without_estimate: int}>
     */
    private function planned(array $ids, string $until): array
    {
        if ($ids === []) {
            return [];
        }

        $timer = DB::table('task_work_sessions')
            ->whereNotNull('ended_at')
            ->groupBy('task_id')
            ->selectRaw('task_id, sum(timestampdiff(minute, started_at, ended_at)) as minutes');

        return Task::query()->toBase()
            ->leftJoinSub($timer, 'timer', 'timer.task_id', '=', 'tasks.id')
            ->whereIn('tasks.assignee_id', $ids)
            ->whereIn('tasks.status', [TaskStatus::Todo->value, TaskStatus::InProgress->value, TaskStatus::ChangesRequested->value])
            ->whereNotNull('tasks.due_date')
            ->where('tasks.due_date', '<=', $until)
            ->whereIn('tasks.project_id', fn (QueryBuilder $q) => $q->select('id')->from('projects')->whereNull('deleted_at'))
            ->whereIn('tasks.sub_project_id', fn (QueryBuilder $q) => $q->select('id')->from('sub_projects')->whereNull('deleted_at'))
            ->groupBy('tasks.assignee_id')
            ->selectRaw('tasks.assignee_id as id')
            ->selectRaw('coalesce(sum(greatest(tasks.estimate_minutes - coalesce(timer.minutes, 0), 0)), 0) as minutes')
            ->selectRaw('count(*) as tasks')
            ->selectRaw('sum(tasks.estimate_minutes is null) as without_estimate')
            ->get()
            ->mapWithKeys(fn (object $r) => [(int) $r->id => ['minutes' => (int) $r->minutes, 'tasks' => (int) $r->tasks, 'without_estimate' => (int) $r->without_estimate]])
            ->all();
    }

    /**
     * Work log minutes per person started in the week (deleted rows left out).
     *
     * @param  list<int>  $ids
     * @param  array{0: string, 1: string}  $range
     * @return array<int, int>
     */
    private function logged(array $ids, array $range): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('work_activity_logs')
            ->whereNull('deleted_at')
            ->whereIn('user_id', $ids)
            ->where('started_at', '>=', $range[0])
            ->where('started_at', '<', $range[1])
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(timestampdiff(second, started_at, ended_at)) as seconds')
            ->pluck('seconds', 'user_id')
            ->map(fn ($s) => intdiv((int) $s, 60))
            ->all();
    }
}
