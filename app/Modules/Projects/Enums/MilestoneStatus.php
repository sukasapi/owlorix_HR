<?php

namespace App\Modules\Projects\Enums;

/** Worked out when read, never stored (docs/14 3.2). */
enum MilestoneStatus: string
{
    case Done = 'done';
    case Overdue = 'overdue';
    // Due today or within the next 7 days
    case Soon = 'soon';
    case Scheduled = 'scheduled';
}
