<?php

namespace App\Modules\Projects\Policies;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMilestone;

/** Everyone who sees a project sees its milestones; people who manage projects set them (docs/14 1). */
class ProjectMilestonePolicy
{
    public function create(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::ManageProjects);
    }

    public function update(User $user, ProjectMilestone $milestone): bool
    {
        return $user->hasPermission(Permission::ManageProjects);
    }

    public function delete(User $user, ProjectMilestone $milestone): bool
    {
        return $this->update($user, $milestone);
    }

    public function complete(User $user, ProjectMilestone $milestone): bool
    {
        return $this->update($user, $milestone);
    }
}
