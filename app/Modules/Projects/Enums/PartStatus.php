<?php

namespace App\Modules\Projects\Enums;

/**
 * One assignee's part of a task (docs/15): open -> submitted (evidence sent) -> approved | changes_requested
 * (lead reviews); changes_requested -> submitted again.
 */
enum PartStatus: string
{
    case Open = 'open';
    case Submitted = 'submitted';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';

    /** The person can run the timer and send evidence for this part. */
    public function isWorkable(): bool
    {
        return $this === self::Open || $this === self::ChangesRequested;
    }
}
