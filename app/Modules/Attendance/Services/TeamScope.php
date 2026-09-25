<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose work a viewer may look at on the team pages (Tim hari ini, PC diam, Log kerja): Project Managers, Project
 * Directors, and Superadmin see every active person; a Team Lead sees the members of the teams they lead. The
 * viewer is left out; their own day is on Hari ini.
 */
class TeamScope
{
    public function everyone(User $viewer): bool
    {
        return $viewer->hasPermission(Permission::ApproveAnyOvertime) || $viewer->hasPermission(Permission::ViewAllReports);
    }

    /** @return Builder<User> */
    public function people(User $viewer): Builder
    {
        return User::query()
            ->active()
            ->whereKeyNot($viewer->id)
            ->when(! $this->everyone($viewer), fn (Builder $q) => $q->whereHas('teams', fn (Builder $t) => $t->where('lead_user_id', $viewer->id)));
    }

    public function includes(User $viewer, int $userId): bool
    {
        return $this->people($viewer)->whereKey($userId)->exists();
    }
}
