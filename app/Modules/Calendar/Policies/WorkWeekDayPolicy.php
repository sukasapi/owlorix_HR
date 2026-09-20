<?php

namespace App\Modules\Calendar\Policies;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;

class WorkWeekDayPolicy
{
    public function update(User $user): bool
    {
        return $user->hasPermission(Permission::ManageCalendar);
    }
}
