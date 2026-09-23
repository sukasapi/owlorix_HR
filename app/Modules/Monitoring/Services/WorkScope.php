<?php

namespace App\Modules\Monitoring\Services;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\WorkActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Whose work Monitor kerja and Beban kerja show (docs/14 1.1).
 *
 * projects.oversee (PM, PD, Superadmin): the whole studio. Anyone else (Team Lead): the members of the teams they
 * lead, plus every task in the sub projects they lead whoever works on it. Tasks of people who left still count;
 * the workload list only has active people. Tasks in deleted projects or sub projects are left out.
 */
class WorkScope
{
    /** Proposals and rejected proposals are not work (yet, or ever), so no count includes them. */
    public const NOT_WORK = [TaskStatus::Proposed, TaskStatus::Rejected];

    /** @var array<int, array{team_people: list<int>, led_sub_projects: list<int>}> */
    private array $cache = [];

    public function isStudio(User $viewer): bool
    {
        return $viewer->hasPermission(Permission::OverseeProjects);
    }

    /** Why a lead's scope is empty, or null when there is something to show. 'no_team_or_sub_project' */
    public function emptyReason(User $viewer): ?string
    {
        if ($this->isStudio($viewer)) {
            return null;
        }

        $parts = $this->parts($viewer);

        return $parts['team_people'] === [] && $parts['led_sub_projects'] === [] && ! $this->leadsTeam($viewer)
            ? 'no_team_or_sub_project'
            : null;
    }

    /** Beban kerja lists team members only, so leading sub projects alone leaves it empty: 'no_team' */
    public function workloadEmptyReason(User $viewer): ?string
    {
        return $this->isStudio($viewer) || $this->leadsTeam($viewer) ? null : 'no_team';
    }

    public function leadsTeam(User $viewer): bool
    {
        return Team::query()->where('lead_user_id', $viewer->id)->exists();
    }

    /** Tasks in scope that count as work (no proposals, no rejected ones), in live projects and sub projects. */
    public function taskQuery(User $viewer): Builder
    {
        $query = Task::query()
            ->whereNotIn('tasks.status', array_map(fn (TaskStatus $s) => $s->value, self::NOT_WORK))
            ->whereIn('tasks.project_id', fn (QueryBuilder $q) => $q->select('id')->from('projects')->whereNull('deleted_at'))
            ->whereIn('tasks.sub_project_id', fn (QueryBuilder $q) => $q->select('id')->from('sub_projects')->whereNull('deleted_at'));

        if ($this->isStudio($viewer)) {
            return $query;
        }

        $parts = $this->parts($viewer);

        if ($parts['team_people'] === [] && $parts['led_sub_projects'] === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($parts) {
            if ($parts['team_people'] !== []) {
                $q->orWhereIn('tasks.assignee_id', $parts['team_people']);
            }
            if ($parts['led_sub_projects'] !== []) {
                $q->orWhereIn('tasks.sub_project_id', $parts['led_sub_projects']);
            }
        });
    }

    /** Active people whose week Beban kerja shows. */
    public function peopleQuery(User $viewer): Builder
    {
        // Management is not planned on Beban kerja (owner, 2026-09-23): whoever may open this page is left out
        $query = User::query()->active()->withoutPermission(Permission::ViewWorkMonitor->value);

        if ($this->isStudio($viewer)) {
            return $query;
        }

        $people = $this->parts($viewer)['team_people'];

        return $people === [] ? $query->whereRaw('1 = 0') : $query->whereIn('users.id', $people);
    }

    /**
     * Work log rows in scope (not deleted): the studio, or rows written by the lead's team members plus rows on
     * tasks in the sub projects they lead.
     */
    public function logQuery(User $viewer): Builder
    {
        $query = WorkActivityLog::query()
            ->whereIn('work_activity_logs.project_id', fn (QueryBuilder $q) => $q->select('id')->from('projects')->whereNull('deleted_at'));

        if ($this->isStudio($viewer)) {
            return $query;
        }

        $parts = $this->parts($viewer);

        if ($parts['team_people'] === [] && $parts['led_sub_projects'] === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($parts) {
            if ($parts['team_people'] !== []) {
                $q->orWhereIn('work_activity_logs.user_id', $parts['team_people']);
            }
            if ($parts['led_sub_projects'] !== []) {
                $q->orWhereIn('work_activity_logs.task_id', fn (QueryBuilder $t) => $t->select('id')->from('tasks')->whereIn('sub_project_id', $parts['led_sub_projects']));
            }
        });
    }

    /**
     * Projects the viewer can pick in the filter: every live project for the studio view, otherwise the projects
     * with tasks in scope or with a sub project the lead leads.
     *
     * @return list<int>
     */
    public function projectIds(User $viewer): array
    {
        if ($this->isStudio($viewer)) {
            return Project::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $parts = $this->parts($viewer);

        $fromTasks = $this->taskQuery($viewer)->distinct()->pluck('tasks.project_id');
        $fromSubs = $parts['led_sub_projects'] === []
            ? collect()
            : SubProject::query()->whereIn('id', $parts['led_sub_projects'])->distinct()->pluck('project_id');

        return $fromTasks->merge($fromSubs)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    /** @return array{team_people: list<int>, led_sub_projects: list<int>} */
    private function parts(User $viewer): array
    {
        return $this->cache[$viewer->id] ??= [
            'team_people' => User::query()
                ->whereHas('teams', fn ($t) => $t->where('lead_user_id', $viewer->id))
                ->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'led_sub_projects' => SubProject::query()
                ->where('lead_user_id', $viewer->id)
                ->whereIn('project_id', fn (QueryBuilder $q) => $q->select('id')->from('projects')->whereNull('deleted_at'))
                ->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];
    }
}
