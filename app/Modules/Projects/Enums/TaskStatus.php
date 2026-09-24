<?php

namespace App\Modules\Projects\Enums;

/**
 * Task life cycle (docs/13): proposed -> todo | rejected (lead decides). After that the status is worked out from
 * the assignees' parts (docs/15, TaskParts::resolve): todo until someone starts, in_progress while a part is open,
 * changes_requested while a part needs changes, in_review once every part is sent, done when all are approved.
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

    /** Open for work: an assignee whose own part is open or needs changes can run the timer and send evidence. */
    public function isWorkable(): bool
    {
        return in_array($this, [self::Todo, self::InProgress, self::ChangesRequested], true);
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Done, self::Rejected], true);
    }
}
