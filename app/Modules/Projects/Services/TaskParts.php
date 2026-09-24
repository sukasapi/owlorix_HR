<?php

namespace App\Modules\Projects\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\PartStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskAssignee;
use App\Modules\Projects\Models\TaskSubmission;
use App\Modules\Projects\Models\TaskWorkSession;
use App\Modules\Shared\Audit\Auditor;
use Carbon\CarbonImmutable;

/**
 * The assignees of a task and the task status that follows from their parts (docs/15). Every change to a part goes
 * through recompute(), so `tasks.status` always matches the parts. Proposals and rejected tasks keep their status.
 * Changes run under the task row lock (lock()), taken first in the transaction.
 */
class TaskParts
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * The status table of docs/15 section 2, first matching row wins.
     *
     * @param  list<PartStatus>  $parts
     * @param  bool  $started  someone ran the timer, sent evidence, or had a part approved
     */
    public static function resolve(array $parts, bool $started): TaskStatus
    {
        $has = fn (PartStatus $status) => in_array($status, $parts, true);

        return match (true) {
            $parts === [] => TaskStatus::Todo,
            collect($parts)->every(fn (PartStatus $p) => $p === PartStatus::Approved) => TaskStatus::Done,
            $has(PartStatus::ChangesRequested) => TaskStatus::ChangesRequested,
            $has(PartStatus::Open) => $started ? TaskStatus::InProgress : TaskStatus::Todo,
            default => TaskStatus::InReview,
        };
    }

    /**
     * Locks the task row until the transaction ends and reloads the task from it. Every transaction that changes
     * parts calls this first, so two people changing parts of one task at once take turns, and the second one sees
     * the first one's parts before it checks anything (for example, two assignees sending their last evidence
     * together). Must run before any unsaved change to $task, which it would overwrite.
     */
    public function lock(Task $task): void
    {
        $row = Task::query()->whereKey($task->id)->lockForUpdate()->first();
        abort_if($row === null, 404);

        $task->setRawAttributes($row->getAttributes(), true);
        $task->unsetRelation('assignees')->unsetRelation('parts');
    }

    /**
     * Saves the status worked out from the parts; `completed_at` is set on the way to done and cleared otherwise.
     * The caller holds the task lock (lock()).
     */
    public function recompute(Task $task): TaskStatus
    {
        if ($task->status === TaskStatus::Proposed || $task->status === TaskStatus::Rejected) {
            return $task->status;
        }

        /** @var list<PartStatus> $parts */
        $parts = TaskAssignee::query()->where('task_id', $task->id)->lockForUpdate()->pluck('part_status')->all();

        $started = collect($parts)->contains(fn (PartStatus $p) => $p !== PartStatus::Open)
            || TaskWorkSession::query()->where('task_id', $task->id)->exists()
            || TaskSubmission::query()->where('task_id', $task->id)->exists();

        $next = self::resolve($parts, $started);
        $wasDone = $task->status === TaskStatus::Done;

        $task->forceFill([
            'status' => $next,
            'completed_at' => $next === TaskStatus::Done ? ($wasDone && $task->completed_at !== null ? $task->completed_at : now()) : null,
        ]);

        if ($task->isDirty()) {
            $task->save();
        }

        return $next;
    }

    /**
     * Makes the assignees exactly $userIds. A new person gets an open part (a finished task runs again); a removed
     * person loses their part and their running timer on this task stops now. Their sessions stay, without a log.
     *
     * @param  list<int>  $userIds
     * @return bool whether the list changed
     */
    public function sync(Task $task, array $userIds, ?User $by, bool $audit = true, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $wanted = array_values(array_unique(array_map('intval', $userIds)));
        $this->lock($task);
        $before = $this->ids($task);

        $removed = array_values(array_diff($before, $wanted));
        $added = array_values(array_diff($wanted, $before));

        if ($removed === [] && $added === []) {
            return false;
        }

        if ($removed !== []) {
            TaskWorkSession::query()->where('task_id', $task->id)->whereIn('user_id', $removed)->whereNull('ended_at')->update(['ended_at' => $now]);
            TaskAssignee::query()->where('task_id', $task->id)->whereIn('user_id', $removed)->delete();
        }

        foreach ($added as $userId) {
            TaskAssignee::query()->create([
                'task_id' => $task->id,
                'user_id' => $userId,
                'part_status' => PartStatus::Open,
                'assigned_by' => $by?->id,
                'assigned_at' => $now,
            ]);
        }

        $task->unsetRelation('assignees');
        $this->recompute($task);

        if ($audit) {
            $this->auditor->record('task.assignees_changed', $task, ['assignee_ids' => $before], [
                'assignee_ids' => $this->ids($task),
                'assignees_added' => $added,
                'assignees_removed' => $removed,
                'status' => $task->status->value,
            ]);
        }

        return true;
    }

    /** Moves one person's part to $status and recomputes the task. The caller holds the task lock (lock()). */
    public function setPart(Task $task, int $userId, PartStatus $status): void
    {
        TaskAssignee::query()->where('task_id', $task->id)->where('user_id', $userId)->update(['part_status' => $status->value, 'updated_at' => now()]);
        $task->unsetRelation('assignees');
        $this->recompute($task);
    }

    /** @return list<int> assignee ids in assignment order */
    public function ids(Task $task): array
    {
        return TaskAssignee::query()
            ->where('task_id', $task->id)
            ->orderBy('assigned_at')
            ->orderBy('id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
