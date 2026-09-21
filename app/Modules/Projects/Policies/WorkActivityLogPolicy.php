<?php

namespace App\Modules\Projects\Policies;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\WorkActivityLog;

class WorkActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::LogActivity);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::LogActivity);
    }

    public function update(User $user, WorkActivityLog $log): bool
    {
        return $user->hasPermission(Permission::LogActivity) && $log->user_id === $user->id;
    }

    public function delete(User $user, WorkActivityLog $log): bool
    {
        return $this->update($user, $log);
    }
}
