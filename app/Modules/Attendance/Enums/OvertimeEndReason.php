<?php

namespace App\Modules\Attendance\Enums;

enum OvertimeEndReason: string
{
    case ClockOut = 'clock_out';
    case PresenceCheckNoAnswer = 'presence_check_no_answer';
}
