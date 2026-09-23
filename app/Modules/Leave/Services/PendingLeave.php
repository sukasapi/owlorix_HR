<?php

namespace App\Modules\Leave\Services;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Organization\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Leave requests a person may decide, as a query. Mirrors LeaveApprovers::canDecide in SQL so the inbox does not
 * check requests one by one.
 */
class PendingLeave
{
    /** @return Builder<LeaveRequest> */
    public function decidableBy(User $viewer): Builder
    {
        return $this->scoped(LeaveRequest::query()->where('status', LeaveStatus::Pending), $viewer);
    }

    /** @return Builder<LeaveRequest> decided or cancelled requests of the people the viewer decides for */
    public function closedVisibleTo(User $viewer): Builder
    {
        return $this->scoped(LeaveRequest::query()->where('status', '!=', LeaveStatus::Pending), $viewer);
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    private function scoped(Builder $query, User $viewer): Builder
    {
        $query->where('user_id', '!=', $viewer->id);

        if (! $viewer->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        if ($viewer->hasPermission(Permission::ManageLeave)
            || ($viewer->hasPermission(Permission::ApproveLeave) && $viewer->hasPermission(Permission::ApproveAnyLeave))) {
            return $query;
        }

        if (! $viewer->hasPermission(Permission::ApproveLeave)) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereIn('user_id', DB::table('team_user')
                ->join('teams', 'teams.id', '=', 'team_user.team_id')
                ->where('teams.lead_user_id', $viewer->id)
                ->select('team_user.user_id'))
            // A person who leads a team goes to Project Managers, Project Directors, and Superadmin only
            ->whereNotIn('user_id', Team::query()->whereNotNull('lead_user_id')->select('lead_user_id'));
    }
}
