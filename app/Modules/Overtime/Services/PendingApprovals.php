<?php

namespace App\Modules\Overtime\Services;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Overtime requests a person may make the first decision on, as a query. Mirrors OvertimeApprovers::canApprove
 * (3.4.4, release 1 of Q6) in SQL so the inbox and the nav badge do not check requests one by one: a Team Lead sees
 * members of the teams they lead, except people who lead a team themselves; Project Managers and Project Directors
 * see everyone; nobody sees their own.
 */
class PendingApprovals
{
    /** @return Builder<OvertimeRequest> pending requests, including ones still running or waiting for a report */
    public function decidableBy(User $viewer): Builder
    {
        $query = OvertimeRequest::query()
            ->where('status', OvertimeStatus::Pending)
            ->where('user_id', '!=', $viewer->id);

        if (! $viewer->isActive() || ! $viewer->hasPermission(Permission::ApproveOvertime)) {
            return $query->whereRaw('1 = 0');
        }

        if ($viewer->hasPermission(Permission::ApproveAnyOvertime)) {
            return $query;
        }

        return $this->inLedTeams($query, $viewer);
    }

    /** @return Builder<OvertimeRequest> pending requests that can be decided now: overtime ended and report written (3.4.5) */
    public function readyFor(User $viewer): Builder
    {
        return $this->decidableBy($viewer)->whereNotNull('ended_at')->whereNotNull('work_report');
    }

    /** The nav badge: requests waiting for this person's answer that they can answer now. */
    public function countFor(User $viewer): int
    {
        return $this->readyFor($viewer)->count();
    }

    /**
     * Decided requests the viewer may look at: everyone's for people who approve anyone or change decisions,
     * the led teams for a Team Lead. Never their own.
     *
     * @return Builder<OvertimeRequest>
     */
    public function decidedVisibleTo(User $viewer): Builder
    {
        $query = OvertimeRequest::query()
            ->where('status', '!=', OvertimeStatus::Pending)
            ->where('user_id', '!=', $viewer->id);

        if (! $viewer->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        if ($viewer->hasPermission(Permission::ChangeOvertimeDecisions)
            || ($viewer->hasPermission(Permission::ApproveOvertime) && $viewer->hasPermission(Permission::ApproveAnyOvertime))) {
            return $query;
        }

        if ($viewer->hasPermission(Permission::ApproveOvertime)) {
            return $this->inLedTeams($query, $viewer);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @param  Builder<OvertimeRequest>  $query
     * @return Builder<OvertimeRequest>
     */
    private function inLedTeams(Builder $query, User $lead): Builder
    {
        return $query
            ->whereIn('user_id', DB::table('team_user')
                ->join('teams', 'teams.id', '=', 'team_user.team_id')
                ->where('teams.lead_user_id', $lead->id)
                ->select('team_user.user_id'))
            // A person who leads a team goes to Project Managers and Project Directors only (3.4.4)
            ->whereNotIn('user_id', Team::query()->whereNotNull('lead_user_id')->select('lead_user_id'));
    }
}
