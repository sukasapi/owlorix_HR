<?php

namespace App\Modules\Leave\Services;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Organization\Models\Team;
use Illuminate\Support\Collection;

/**
 * Who may decide a leave request (docs/14 4.2), the same rule as overtime (Overtime\Services\OvertimeApprovers):
 * the Team Lead of the person's team, any Project Manager or Project Director, and whoever manages leave
 * (Superadmin). A person who leads a team goes to Project Managers, Project Directors, and Superadmin only.
 * Nobody decides their own request, and a suspended account decides nothing.
 */
class LeaveApprovers
{
    public function canDecide(User $actor, LeaveRequest $request): bool
    {
        $person = $request->user;

        if ($person === null || $actor->is($person) || ! $actor->isActive()) {
            return false;
        }

        if ($actor->hasPermission(Permission::ManageLeave)) {
            return true;
        }

        if (! $actor->hasPermission(Permission::ApproveLeave)) {
            return false;
        }

        if ($actor->hasPermission(Permission::ApproveAnyLeave)) {
            return true;
        }

        return $this->teamLeadsOf($person)->contains(fn (User $lead) => $lead->is($actor));
    }

    /** @return Collection<int, User> */
    private function teamLeadsOf(User $person): Collection
    {
        if (Team::query()->where('lead_user_id', $person->id)->exists()) {
            return collect();
        }

        $leadIds = $person->teams()->whereNotNull('lead_user_id')->pluck('lead_user_id')->unique();

        return User::query()->whereIn('id', $leadIds)->whereKeyNot($person->id)->get();
    }
}
