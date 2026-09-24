<?php

namespace App\Modules\Projects\Services;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\PartStatus;
use App\Modules\Projects\Enums\ReviewStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Proposals and evidence waiting for a lead's decision, for Tugas saya and the nav badge. Follows the same rule as
 * SubProjectPolicy::lead and TaskPolicy::review, as a query: evidence counts once no part of the task is still open,
 * and only submissions that Task::waitingSubmissions() would offer for review.
 */
class TaskInbox
{
    public function decisionsWaitingFor(User $user): int
    {
        return $this->waitingQuery($user)?->count() ?? 0;
    }

    /** @return Builder<Task>|null */
    public function waitingQuery(User $user): ?Builder
    {
        $oversees = $user->hasPermission(Permission::OverseeProjects);
        $manages = $user->hasPermission(Permission::ManageProjects);

        if (! $oversees && ! $manages) {
            $leads = SubProject::query()->where('lead_user_id', $user->id)->exists();

            if (! $leads) {
                return null;
            }
        }

        $subProjects = SubProject::query()
            ->select('sub_projects.id')
            ->join('projects', 'projects.id', '=', 'sub_projects.project_id')
            ->whereNull('projects.deleted_at')
            ->when(! $oversees, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('sub_projects.lead_user_id', $user->id)
                ->when($manages, fn (Builder $m) => $m->orWhereNull('sub_projects.lead_user_id'))));

        return Task::query()
            ->whereIn('sub_project_id', $subProjects)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $p) => $p->where('status', TaskStatus::Proposed)->where('created_by', '!=', $user->id))
                ->orWhere(fn (Builder $r) => $r
                    ->whereIn('status', [TaskStatus::InReview, TaskStatus::ChangesRequested])
                    ->whereNotExists(fn (QueryBuilder $open) => $open->selectRaw('1')->from('task_assignees as open_part')
                        ->whereColumn('open_part.task_id', 'tasks.id')
                        ->where('open_part.part_status', PartStatus::Open->value))
                    ->whereExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('task_submissions as ts')
                        ->whereColumn('ts.task_id', 'tasks.id')
                        ->where('ts.review_status', ReviewStatus::Pending->value)
                        ->when(! $oversees, fn (QueryBuilder $mine) => $mine->where('ts.submitted_by', '!=', $user->id))
                        ->whereExists(fn (QueryBuilder $part) => $part->selectRaw('1')->from('task_assignees as sent_part')
                            ->whereColumn('sent_part.task_id', 'ts.task_id')
                            ->whereColumn('sent_part.user_id', 'ts.submitted_by')
                            ->where('sent_part.part_status', PartStatus::Submitted->value))
                        ->whereNotExists(fn (QueryBuilder $newer) => $newer->selectRaw('1')->from('task_submissions as newer')
                            ->whereColumn('newer.task_id', 'ts.task_id')
                            ->whereColumn('newer.submitted_by', 'ts.submitted_by')
                            ->whereColumn('newer.id', '>', 'ts.id')))));
    }
}
