<?php

namespace App\Modules\Attendance\Enums;

enum ShiftStatus: string
{
    case Open = 'open';
    case Prompted = 'prompted';
    case Overtime = 'overtime';
    case ReportDue = 'report_due';
    case Closed = 'closed';
    case Interrupted = 'interrupted';
    case NeedsReview = 'needs_review';

    /** Statuses of a shift that has no clock-out yet. */
    public function isLive(): bool
    {
        return in_array($this, [self::Open, self::Prompted, self::Overtime, self::Interrupted], true);
    }
}
