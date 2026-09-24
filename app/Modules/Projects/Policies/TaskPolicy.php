<?php

namespace App\Modules\Projects\Policies;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\PartStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskSubmission;

class TaskPolicy
{
    public function __construct(private readonly SubProjectPolicy $subProjects) {}

    public function view(User $user, Task $task): bool
    {
        return $user->hasPermission(Permission::ViewProjects) || $user->hasPermission(Permission::ManageProjects);
    }

    /** The lead edits any task (assignees included); the proposer can still fix a proposal nobody decided yet. */
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

    /** A task nobody is on can be taken by a project member (or a manager). */
    public function claim(User $user, Task $task): bool
    {
        return $task->status === TaskStatus::Todo
            && ! $task->parts()->exists()
            && $user->hasPermission(Permission::LogActivity)
            && ($user->hasPermission(Permission::ManageProjects) || SubProjectPolicy::isMember($user, $task->project_id));
    }

    /** Timer and evidence: only an assignee whose own part is open or needs changes (docs/15 section 3). */
    public function work(User $user, Task $task): bool
    {
        return $task->status->isWorkable()
            && $user->hasPermission(Permission::LogActivity)
            && ($task->partOf($user)?->part_status->isWorkable() ?? false);
    }

    /**
     * Reviewing evidence, per submission: the lead of the sub project, once no part is still open (everyone sent in
     * this round), never their own submission unless they oversee all projects. Without a submission: whether any
     * waiting submission on the task is theirs to review now.
     */
    public function review(User $user, Task $task, ?TaskSubmission $submission = null): bool
    {
        if (! $this->reviewOpen($user, $task)) {
            return false;
        }

        if ($submission === null) {
            return $task->waitingSubmissions()
                ->when(! $user->hasPermission(Permission::OverseeProjects), fn ($q) => $q->where('task_submissions.submitted_by', '!=', $user->id))
                ->exists();
        }

        return $submission->task_id === $task->id
            && self::mayReviewSender($user, $submission)
            && $task->waitingSubmissions()->whereKey($submission->id)->exists();
    }

    /**
     * The part of the review rule that is the same for every submission on the task: the viewer leads the sub
     * project and no part is still open. A page that lists many submissions checks this once.
     */
    public function reviewOpen(User $user, Task $task): bool
    {
        return in_array($task->status, [TaskStatus::InReview, TaskStatus::ChangesRequested], true)
            && $this->leads($user, $task)
            && ! $task->parts()->where('part_status', PartStatus::Open)->exists();
    }

    /** Nobody reviews their own evidence, except someone who oversees all projects. */
    public static function mayReviewSender(User $user, TaskSubmission $submission): bool
    {
        return $submission->submitted_by !== $user->id || $user->hasPermission(Permission::OverseeProjects);
    }

    private function leads(User $user, Task $task): bool
    {
        return $task->subProject !== null && $this->subProjects->lead($user, $task->subProject);
    }
}
