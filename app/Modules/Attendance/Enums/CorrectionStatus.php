<?php

namespace App\Modules\Attendance\Enums;

enum CorrectionStatus: string
{
    case Proposed = 'proposed';
    case Applied = 'applied';
    case Declined = 'declined';
}
