<?php

namespace App\Modules\Projects\Policies;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectLink;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\TaskAssignee;

/**
 * Document links (docs/16). Only people involved in the project open them: members, sub project leads, and people
 * on one of its tasks, plus everyone who manages projects, because they are the ones who set the links.
 */
class ProjectLinkPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::ManageProjects) || self::isInvolved($user, $project->id);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::ManageProjects);
    }

    public function update(User $user, ProjectLink $link): bool
    {
        return $user->hasPermission(Permission::ManageProjects);
    }

    public function delete(User $user, ProjectLink $link): bool
    {
        return $this->update($user, $link);
    }

    public function move(User $user, ProjectLink $link): bool
    {
        return $this->update($user, $link);
    }

    /** Task assignees count even when nobody added them as members (TaskRequest accepts any active person). */
    public static function isInvolved(User $user, int $projectId): bool
    {
        return SubProjectPolicy::isMember($user, $projectId)
            || SubProject::query()->where('project_id', $projectId)->where('lead_user_id', $user->id)->exists()
            || TaskAssignee::query()
                ->where('user_id', $user->id)
                ->whereHas('task', fn ($q) => $q->where('project_id', $projectId))
                ->exists();
    }
}
