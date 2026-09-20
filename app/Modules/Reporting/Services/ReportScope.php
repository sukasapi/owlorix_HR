<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use Illuminate\Support\Collection;

/**
 * Whose numbers a person may read in Laporan. Superadmin (reports.view_all) reads everyone. Inside Management the
 * reading follows overtime approval in release 1 (Q6): a Project Manager or Project Director reads everyone, a
 * Team Lead reads the members of the teams they lead.
 */
class ReportScope
{
    public function seesEveryone(User $viewer): bool
    {
        return $viewer->hasPermission(Permission::ViewAllReports)
            || ($viewer->hasPermission(Permission::ViewTeamReports) && $viewer->hasPermission(Permission::ApproveAnyOvertime));
    }

    public function canView(User $viewer): bool
    {
        return $viewer->hasPermission(Permission::ViewAllReports) || $viewer->hasPermission(Permission::ViewTeamReports);
    }

    /**
     * Teams the viewer can filter by, ordered by name, with their members (including accounts that were removed).
     *
     * @return Collection<int, Team>
     */
    public function teams(User $viewer): Collection
    {
        if (! $this->canView($viewer)) {
            return collect();
        }

        return Team::query()
            ->when(! $this->seesEveryone($viewer), fn ($q) => $q->where('lead_user_id', $viewer->id))
            ->with(['members' => fn ($q) => $q->withTrashed()->select('users.id')])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function includes(User $viewer, int $userId): bool
    {
        if (! $this->canView($viewer)) {
            return false;
        }

        if ($this->seesEveryone($viewer)) {
            return true;
        }

        return $this->teams($viewer)->contains(fn (Team $team) => $team->members->contains('id', $userId));
    }
}
