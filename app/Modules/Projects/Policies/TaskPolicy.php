<?php

namespace App\Modules\Projects\Policies;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Task;

class TaskPolicy
{
    public function __construct(private readonly SubProjectPolicy $subProjects) {}

    public function view(User $user, Task $task): bool
    {
        return $user->hasPermission(Permission::ViewProjects) || $user->hasPermission(Permission::ManageProjects);
    }

    /** The lead edits any task; the proposer can still fix a proposal nobody decided yet. */
    public function update(User $user, Task $task): bool
    {
        return $this->leads($user, $task)
            || ($task->status === TaskStatus::Proposed && $task->created_by === $user->id);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->leads($user, $task);
    }

    public function decide(User $user, Task $task): bool
    {
        return $task->status === TaskStatus::Proposed && $this->leads($user, $task);
    }

    /** An unassigned task can be taken by a project member (or a manager). */
    public function claim(User $user, Task $task): bool
    {
        return $task->status === TaskStatus::Todo
            && $task->assignee_id === null
            && $user->hasPermission(Permission::LogActivity)
            && ($user->hasPermission(Permission::ManageProjects) || SubProjectPolicy::isMember($user, $task->project_id));
    }

    /** Timer and evidence: only the assignee, while the task is open for work. */
    public function work(User $user, Task $task): bool
    {
        return $task->assignee_id === $user->id
            && $task->status->isWorkable()
            && $user->hasPermission(Permission::LogActivity);
    }

    /** Nobody approves their own evidence, except someone who oversees all projects. */
    public function review(User $user, Task $task): bool
    {
        return $task->status === TaskStatus::InReview
            && $this->leads($user, $task)
            && ($task->assignee_id !== $user->id || $user->hasPermission(Permission::OverseeProjects));
    }

    private function leads(User $user, Task $task): bool
    {
        return $task->subProject !== null && $this->subProjects->lead($user, $task->subProject);
    }
}
