<?php

namespace App\Modules\Leave\Enums;

enum LeaveDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function status(): LeaveStatus
    {
        return LeaveStatus::from($this->value);
    }
}
