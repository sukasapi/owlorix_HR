<?php

namespace App\Modules\Overtime\Services;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use App\Modules\Overtime\Models\OvertimeRequest;
use Illuminate\Support\Collection;

/**
 * Who may decide an overtime request (3.4.4, release 1 of Q6): the Team Lead of the person's team, any Project
 * Manager, any Project Director. Nobody decides their own request. A person who leads a team goes to Project
 * Managers and Project Directors only.
 */
class OvertimeApprovers
{
    public function canApprove(User $approver, OvertimeRequest $request): bool
    {
        $person = $request->user;

        if ($person === null || $approver->is($person) || ! $approver->isActive() || ! $approver->hasPermission(Permission::ApproveOvertime)) {
            return false;
        }

        if ($approver->hasPermission(Permission::ApproveAnyOvertime)) {
            return true;
        }

        return $this->teamLeadsOf($person)->contains(fn (User $lead) => $lead->is($approver));
    }

    /** @return Collection<int, User> */
    public function for(OvertimeRequest $request): Collection
    {
        $person = $request->user;

        $leads = $this->teamLeadsOf($person)
            ->filter(fn (User $lead) => $lead->isActive() && $lead->hasPermission(Permission::ApproveOvertime));

        $anyone = User::query()
            ->active()
            ->permission(Permission::ApproveAnyOvertime->value)
            ->whereKeyNot($person->id)
            ->get()
            ->filter(fn (User $user) => $user->hasPermission(Permission::ApproveOvertime));

        return $leads->concat($anyone)->unique('id')->values();
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
