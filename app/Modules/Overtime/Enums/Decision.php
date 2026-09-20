<?php

namespace App\Modules\Overtime\Enums;

enum Decision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function status(): OvertimeStatus
    {
        return OvertimeStatus::from($this->value);
    }
}
