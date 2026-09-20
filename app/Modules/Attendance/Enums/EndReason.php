<?php

namespace App\Modules\Attendance\Enums;

enum EndReason: string
{
    case Manual = 'manual';
    case AutoNoAnswer = 'auto_no_answer';
    case ShutdownTimeout = 'shutdown_timeout';
    case Superadmin = 'superadmin';
}
