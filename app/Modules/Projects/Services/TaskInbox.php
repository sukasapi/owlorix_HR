<?php

namespace App\Modules\Projects\Services;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/**
 * Proposals and evidence waiting for a lead's decision, for Tugas saya and the nav badge. Follows the same rule as
 * SubProjectPolicy::lead and TaskPolicy::review, as a query.
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
                ->orWhere(fn (Builder $r) => $r->where('status', TaskStatus::InReview)
                    ->when(! $oversees, fn (Builder $mine) => $mine->where(fn (Builder $a) => $a->whereNull('assignee_id')->orWhere('assignee_id', '!=', $user->id)))));
    }
}
