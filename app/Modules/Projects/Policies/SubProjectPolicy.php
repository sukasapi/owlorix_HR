<?php

namespace App\Modules\Projects\Policies;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\SubProject;

/**
 * Who does what in a sub project (docs/13). The lead decides proposals and reviews evidence. Without a lead anyone
 * who manages projects does it; people who oversee projects (PM, PD, Superadmin) can always do it.
 */
class SubProjectPolicy
{
    public function create(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::ManageProjects);
    }

    public function update(User $user, SubProject $subProject): bool
    {
        return $user->hasPermission(Permission::ManageProjects);
    }

    public function lead(User $user, SubProject $subProject): bool
    {
        if ($user->hasPermission(Permission::OverseeProjects)) {
            return true;
        }

        if ($subProject->lead_user_id !== null) {
            return $subProject->lead_user_id === $user->id;
        }

        return $user->hasPermission(Permission::ManageProjects);
    }

    /** A lead adds tasks straight to the list; everyone else proposes. */
    public function createTask(User $user, SubProject $subProject): bool
    {
        return $this->open($subProject) && $this->lead($user, $subProject);
    }

    public function proposeTask(User $user, SubProject $subProject): bool
    {
        return $this->open($subProject)
            && $user->hasPermission(Permission::LogActivity)
            && ($user->hasPermission(Permission::ManageProjects) || self::isMember($user, $subProject->project_id));
    }

    public static function isMember(User $user, int $projectId): bool
    {
        return ProjectMember::query()->where('project_id', $projectId)->where('user_id', $user->id)->exists();
    }

    private function open(SubProject $subProject): bool
    {
        return $subProject->status !== ProjectStatus::Done && $subProject->project?->status !== ProjectStatus::Done;
    }
}
