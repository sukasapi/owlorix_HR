<?php

namespace App\Modules\Projects\Enums;

enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
}
