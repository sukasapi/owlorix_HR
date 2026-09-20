<?php

namespace App\Modules\Calendar\Policies;

use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;

class CalendarDayPolicy
{
    /** The calendar page: Superadmin and Management. */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageCalendar) || $user->hasPermission(Permission::OpenWorkdays);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageCalendar);
    }

    public function update(User $user, CalendarDay $day): bool
    {
        return $user->hasPermission(Permission::ManageCalendar);
    }

    public function delete(User $user, CalendarDay $day): bool
    {
        return $user->hasPermission(Permission::ManageCalendar);
    }
}
