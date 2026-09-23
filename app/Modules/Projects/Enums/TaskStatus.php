<?php

namespace App\Modules\Projects\Enums;

/**
 * Task life cycle (docs/13):
 * proposed -> todo | rejected (lead decides); todo -> in_progress (timer) -> in_review (evidence sent)
 * -> done | changes_requested (lead reviews); changes_requested -> in_progress -> in_review again.
 */
enum TaskStatus: string
{
    case Proposed = 'proposed';
    case Rejected = 'rejected';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Done = 'done';

    /** The assignee can run the timer and send evidence. */
    public function isWorkable(): bool
    {
        return in_array($this, [self::Todo, self::InProgress, self::ChangesRequested], true);
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Done, self::Rejected], true);
    }
}
