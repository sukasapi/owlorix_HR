<?php

namespace App\Modules\Projects\Policies;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;

/** Project Director and Superadmin shape the pipeline; everyone else only sees stages on tasks. */
class PipelineStagePolicy
{
    public function manage(User $user): bool
    {
        return $user->hasPermission(Permission::ManagePipeline);
    }
}
