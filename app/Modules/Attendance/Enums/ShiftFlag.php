<?php

namespace App\Modules\Attendance\Enums;

enum ShiftFlag: string
{
    case ClockMismatch = 'clock_mismatch';
    case OfflineSignIn = 'offline_sign_in';
    case LateClaim = 'late_claim';
    /** The PC resumed the shift after a gap the server could not measure to be within the resume window (3.7.3) */
    case GapUnverified = 'gap_unverified';
}
