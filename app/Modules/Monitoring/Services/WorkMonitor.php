<?php

namespace App\Modules\Monitoring\Services;

use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\ReviewStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\PipelineStage;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMilestone;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskSubmission;
use App\Modules\Projects\Services\HourBudget;
use App\Modules\Projects\Services\TaskPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for Monitor kerja (docs/14 5.1), all read from tasks, reviews, work logs, and milestones in the viewer's
 * WorkScope. Counting happens in SQL with GROUP BY; PHP only arranges the grouped rows.
 *
 * Weeks start on Monday in the studio zone. The period is the current week plus the weeks before it. Task buckets:
 * not started (todo), in progress (in_progress, changes_requested), in review (in_review), done. Proposals and
 * rejected proposals are left out everywhere (WorkScope::NOT_WORK).
 */
class WorkMonitor
{
    public const WEEK_OPTIONS = [4, 8, 12];

    public const DUE_SOON_DAYS = 7;

    public const MILESTONE_DAYS = 30;

    /** Longest lists sent to the page; the totals are always sent */
    public const LIST_LIMIT = 30;

    private const OPEN = ['todo', 'in_progress', 'in_review', 'changes_requested'];

    public function __construct(
        private readonly WorkScope $scope,
        private readonly HourBudget $hourBudget,
    ) {}

    /** @return array<string, mixed> */
    public function build(User $viewer, ?int $projectId, int $weeks, bool $withBudgets): array
    {
        $zone = Time::zone();
        $today = CarbonImmutable::now($zone)->startOfDay();
        $thisWeek = $today->subDays($today->dayOfWeekIso - 1);
        $from = $thisWeek->subWeeks($weeks - 1);
        $until = $thisWeek->addWeek();
        $range = [Time::db($from), Time::db($until)];

        $projects = Project::query()
            ->whereIn('id', $this->scope->projectIds($viewer))
            ->orderByRaw("FIELD(status, 'active', 'planned', 'done')")
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'status', 'budget_minutes'])
            ->keyBy('id');

        // A project outside the scope is ignored rather than shown empty
        if ($projectId !== null && ! $projects->has($projectId)) {
            $projectId = null;
        }

        $tasks = fn (): Builder => $this->scope->taskQuery($viewer)->when($projectId, fn (Builder $q) => $q->where('tasks.project_id', $projectId));

        $status = $this->statusRows($tasks(), $projectId, $projects, $today, $range);
        $headline = [
            'open' => $status->sum('open'),
            'overdue' => $status->sum('overdue'),
            'in_review' => $status->sum('review'),
            'done_in_period' => $status->sum('done_in_period'),
        ];

        $data = [
            'period' => [
                'from' => $from->toDateString(),
                'until' => $today->toDateString(),
                'weeks' => $weeks,
            ],
            'today' => $today->toDateString(),
            'filters' => ['project' => $projectId, 'weeks' => $weeks],
            'options' => [
                'projects' => $projects->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'code' => $p->code])->values()->all(),
                'weeks' => self::WEEK_OPTIONS,
            ],
            'scope' => [
                'studio' => $this->scope->isStudio($viewer),
                'empty_reason' => $this->scope->emptyReason($viewer),
            ],
            'headline' => $headline,
            'throughput' => $this->throughput($tasks(), $from, $weeks, $range),
            'status' => [
                'by' => $projectId === null ? 'project' : 'sub_project',
                'rows' => $status->map(fn (array $r) => [
                    'id' => $r['id'], 'name' => $r['name'], 'code' => $r['code'], 'href' => $r['href'],
                    'todo' => $r['todo'], 'doing' => $r['doing'], 'review' => $r['review'], 'done' => $r['done'],
                ])->values()->all(),
            ],
            'stages' => $projectId === null ? null : $this->stages($tasks()),
            'hours' => $this->hours($viewer, $projectId, $projects, $range),
            'review' => $this->review($tasks(), $range),
            'due' => $this->due($tasks(), $today),
            'milestones' => $this->milestones($projectId === null ? $projects->keys()->all() : [$projectId], $projects, $today),
        ];

        // Budget holders only; everyone else does not receive the key at all (docs/14 3.3)
        if ($withBudgets) {
            $data['budgets'] = $this->budgets($projectId === null ? $projects : $projects->filter(fn (Project $p) => $p->id === $projectId));
        }

        return $data;
    }

    /**
     * Tasks per project (or per sub project of the chosen one) in the four buckets, plus the headline counts.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array{0: string, 1: string}  $range
     * @return Collection<int, array<string, mixed>>
     */
    private function statusRows(Builder $tasks, ?int $projectId, Collection $projects, CarbonImmutable $today, array $range): Collection
    {
        $key = $projectId === null ? 'tasks.project_id' : 'tasks.sub_project_id';
        $open = "'".implode("','", self::OPEN)."'";

        $rows = $tasks->toBase()
            ->groupBy($key)
            ->selectRaw("{$key} as id")
            ->selectRaw("sum(tasks.status = 'todo') as todo")
            ->selectRaw("sum(tasks.status in ('in_progress', 'changes_requested')) as doing")
            ->selectRaw("sum(tasks.status = 'in_review') as review")
            ->selectRaw("sum(tasks.status = 'done') as done")
            ->selectRaw("sum(tasks.status in ({$open}) and tasks.due_date < ?) as overdue", [$today->toDateString()])
            ->selectRaw("sum(tasks.status = 'done' and tasks.completed_at >= ? and tasks.completed_at < ?) as done_in_period", $range)
            ->get();

        $subs = $projectId === null
            ? collect()
            : SubProject::query()->whereIn('id', $rows->pluck('id'))->get(['id', 'project_id', 'name'])->keyBy('id');

        return $rows
            ->map(function (object $row) use ($projectId, $projects, $subs) {
                $counts = [
                    'todo' => (int) $row->todo,
                    'doing' => (int) $row->doing,
                    'review' => (int) $row->review,
                    'done' => (int) $row->done,
                    'overdue' => (int) $row->overdue,
                    'done_in_period' => (int) $row->done_in_period,
                ];
                $counts['open'] = $counts['todo'] + $counts['doing'] + $counts['review'];

                if ($projectId === null) {
                    $project = $projects->get($row->id);

                    return $project === null ? null : [
                        'id' => $project->id, 'name' => $project->name, 'code' => $project->code,
                        'href' => route('projects.show', $project->id, false), ...$counts,
                    ];
                }

                $sub = $subs->get($row->id);

                return $sub === null ? null : [
                    'id' => $sub->id, 'name' => $sub->name, 'code' => null,
                    'href' => route('projects.sub.show', [$sub->project_id, $sub->id], false), ...$counts,
                ];
            })
            ->filter()
            // Most open work first: that is where things pile up
            ->sortBy([['open', 'desc'], ['name', 'asc']])
            ->values();
    }

    /**
     * Tasks created and finished per studio week, oldest first, every week present.
     *
     * @param  array{0: string, 1: string}  $range
     * @return list<array{week: string, created: int, done: int}>
     */
    private function throughput(Builder $tasks, CarbonImmutable $from, int $weeks, array $range): array
    {
        $created = (clone $tasks)->toBase()
            ->where('tasks.created_at', '>=', $range[0])->where('tasks.created_at', '<', $range[1])
            ->groupBy('week')->selectRaw($this->weekOf('tasks.created_at').' as week, count(*) as n')
            ->pluck('n', 'week');

        $done = (clone $tasks)->toBase()
            ->where('tasks.status', TaskStatus::Done->value)
            ->where('tasks.completed_at', '>=', $range[0])->where('tasks.completed_at', '<', $range[1])
            ->groupBy('week')->selectRaw($this->weekOf('tasks.completed_at').' as week, count(*) as n')
            ->pluck('n', 'week');

        $out = [];
        for ($i = 0; $i < $weeks; $i++) {
            $week = $from->addWeeks($i)->toDateString();
            $out[] = ['week' => $week, 'created' => (int) ($created[$week] ?? 0), 'done' => (int) ($done[$week] ?? 0)];
        }

        return $out;
    }

    /**
     * Buckets per pipeline stage of the chosen project, in pipeline order; tasks without a stage last.
     *
     * @return list<array{id: ?int, name: ?string, phase: ?string, todo: int, doing: int, review: int, done: int}>
     */
    private function stages(Builder $tasks): array
    {
        $rows = $tasks->toBase()
            ->groupBy('tasks.stage_id')
            ->selectRaw('tasks.stage_id as id')
            ->selectRaw("sum(tasks.status = 'todo') as todo")
            ->selectRaw("sum(tasks.status in ('in_progress', 'changes_requested')) as doing")
            ->selectRaw("sum(tasks.status = 'in_review') as review")
            ->selectRaw("sum(tasks.status = 'done') as done")
            ->get()
            ->keyBy(fn (object $r) => $r->id === null ? 'none' : (int) $r->id);

        if ($rows->isEmpty()) {
            return [];
        }

        $counts = fn (object $r) => ['todo' => (int) $r->todo, 'doing' => (int) $r->doing, 'review' => (int) $r->review, 'done' => (int) $r->done];

        $out = PipelineStage::query()->whereIn('id', $rows->keys()->filter(fn ($k) => $k !== 'none'))->ordered()->get()
            ->map(fn (PipelineStage $s) => ['id' => $s->id, 'name' => $s->name, 'phase' => $s->phase->value, ...$counts($rows[$s->id])])
            ->all();

        if ($rows->has('none')) {
            $out[] = ['id' => null, 'name' => null, 'phase' => null, ...$counts($rows['none'])];
        }

        return array_values($out);
    }

    /**
     * Work log minutes per project in the period, largest first.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array{0: string, 1: string}  $range
     * @return list<array{id: int, name: string, code: ?string, href: string, minutes: int}>
     */
    private function hours(User $viewer, ?int $projectId, Collection $projects, array $range): array
    {
        $seconds = $this->scope->logQuery($viewer)->toBase()
            ->when($projectId, fn ($q) => $q->where('work_activity_logs.project_id', $projectId))
            ->whereNull('work_activity_logs.deleted_at')
            ->where('work_activity_logs.started_at', '>=', $range[0])
            ->where('work_activity_logs.started_at', '<', $range[1])
            ->groupBy('work_activity_logs.project_id')
            ->selectRaw('work_activity_logs.project_id as id, sum(timestampdiff(second, work_activity_logs.started_at, work_activity_logs.ended_at)) as seconds')
            ->pluck('seconds', 'id');

        // Logs on projects the viewer cannot pick (none of their tasks there) still show under the project's name
        $missing = $seconds->keys()->map(fn ($id) => (int) $id)->reject(fn (int $id) => $projects->has($id));
        $names = $missing->isEmpty() ? collect() : Project::query()->whereIn('id', $missing)->get(['id', 'name', 'code'])->keyBy('id');

        return $seconds
            ->map(function ($value, $id) use ($projects, $names) {
                $project = $projects->get((int) $id) ?? $names->get((int) $id);

                return $project === null ? null : [
                    'id' => $project->id, 'name' => $project->name, 'code' => $project->code,
                    'href' => route('projects.show', $project->id, false), 'minutes' => intdiv((int) $value, 60),
                ];
            })
            ->filter(fn ($row) => $row !== null && $row['minutes'] > 0)
            ->sortBy([['minutes', 'desc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Logged hours to date against the hour budget, for projects that have one (budget holders only).
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array{id: int, name: string, code: ?string, href: string, budget_minutes: int, logged_minutes: int}>
     */
    private function budgets(Collection $projects): array
    {
        $withBudget = $projects->filter(fn (Project $p) => $p->budget_minutes !== null);
        $logged = $this->hourBudget->loggedByProject($withBudget->pluck('id')->all());

        return $withBudget
            ->map(fn (Project $p) => [
                'id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'href' => route('projects.show', $p->id, false),
                'budget_minutes' => $p->budget_minutes, 'logged_minutes' => $logged[$p->id] ?? 0,
            ])
            // Closest to or past the budget first
            ->sortByDesc(fn (array $r) => $r['logged_minutes'] / max(1, $r['budget_minutes']))
            ->values()
            ->all();
    }

    /**
     * Reviews given in the period on tasks in scope: how many asked for changes, and the median wait from evidence
     * sent to review.
     *
     * @param  array{0: string, 1: string}  $range
     * @return array{reviewed: int, changes_requested: int, median_wait_minutes: ?int}
     */
    private function review(Builder $tasks, array $range): array
    {
        $rows = TaskSubmission::query()->toBase()
            ->whereIn('task_id', $tasks->select('tasks.id'))
            ->whereIn('review_status', [ReviewStatus::Approved->value, ReviewStatus::ChangesRequested->value])
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at', '>=', $range[0])
            ->where('reviewed_at', '<', $range[1])
            ->selectRaw('review_status, greatest(0, timestampdiff(second, created_at, reviewed_at)) as waited')
            ->get();

        $waits = $rows->pluck('waited')->map(fn ($s) => (int) $s)->sort()->values();
        $n = $waits->count();
        $median = match (true) {
            $n === 0 => null,
            $n % 2 === 1 => $waits[intdiv($n, 2)],
            default => intdiv($waits[$n / 2 - 1] + $waits[$n / 2], 2),
        };

        return [
            'reviewed' => $n,
            'changes_requested' => $rows->where('review_status', ReviewStatus::ChangesRequested->value)->count(),
            'median_wait_minutes' => $median === null ? null : intdiv($median, 60),
        ];
    }

    /**
     * Open tasks past their due date or due within a week, earliest first.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function due(Builder $tasks, CarbonImmutable $today): array
    {
        $query = $tasks
            ->whereIn('tasks.status', self::OPEN)
            ->whereNotNull('tasks.due_date')
            ->where('tasks.due_date', '<=', $today->addDays(self::DUE_SOON_DAYS)->toDateString());

        $total = (clone $query)->count();

        $rows = $query
            ->with(['project:id,name,code', 'subProject:id,name', 'assignee:id,name,nickname,username,avatar_path'])
            ->orderBy('tasks.due_date')
            ->orderBy('tasks.id')
            ->limit(self::LIST_LIMIT)
            ->get(['tasks.id', 'tasks.title', 'tasks.status', 'tasks.project_id', 'tasks.sub_project_id', 'tasks.assignee_id', 'tasks.due_date'])
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status->value,
                'due_date' => $task->due_date->format('Y-m-d'),
                'days_until' => (int) $today->diffInDays(CarbonImmutable::parse($task->due_date->format('Y-m-d'), $today->getTimezone()), false),
                'project' => $task->project ? ['id' => $task->project->id, 'name' => $task->project->name, 'code' => $task->project->code] : null,
                'sub_project' => $task->subProject ? ['id' => $task->subProject->id, 'name' => $task->subProject->name] : null,
                'assignee' => TaskPresenter::person($task->assignee),
            ])
            ->all();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Open milestones that are overdue or due in the next 30 days, earliest first.
     *
     * @param  list<int>  $projectIds
     * @param  Collection<int, Project>  $projects
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function milestones(array $projectIds, Collection $projects, CarbonImmutable $today): array
    {
        if ($projectIds === []) {
            return ['total' => 0, 'rows' => []];
        }

        $query = ProjectMilestone::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('done_at')
            ->where('due_date', '<=', $today->addDays(self::MILESTONE_DAYS)->toDateString());

        $total = (clone $query)->count();

        $rows = $query->orderBy('due_date')->orderBy('id')->limit(self::LIST_LIMIT)->get()
            ->map(function (ProjectMilestone $m) use ($projects, $today) {
                $project = $projects->get($m->project_id);

                return [
                    ...TaskPresenter::milestone($m, $today),
                    'project' => $project ? ['id' => $project->id, 'name' => $project->name, 'code' => $project->code] : null,
                ];
            })
            ->all();

        return ['total' => $total, 'rows' => $rows];
    }

    /** SQL for the Monday (studio date) of the week a UTC datetime column falls in. */
    private function weekOf(string $column): string
    {
        // Studio zone offset in minutes; Asia/Jakarta has no daylight saving time
        $offset = (int) CarbonImmutable::now(Time::zone())->utcOffset();
        $local = "date_add({$column}, interval {$offset} minute)";

        return "date_format(date_sub(date({$local}), interval weekday({$local}) day), '%Y-%m-%d')";
    }
}
