<?php

namespace App\Modules\Projects\Policies;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewProjects) || $user->hasPermission(Permission::ManageProjects);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageProjects);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::ManageProjects);
    }

    public function assign(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::ManageProjects);
    }
}
