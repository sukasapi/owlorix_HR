<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Correction;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may correct whose records (3.10). Superadmin applies corrections for anyone. Management proposes for their
 * people with the scope of overtime approvals (3.4.4, release 1 of Q6): a Team Lead for members of the teams they
 * lead, except people who lead a team themselves; a Project Manager or Project Director for anyone. Nobody proposes,
 * applies or declines a correction of their own records, whatever their roles.
 */
class CorrectionScope
{
    public function canApply(User $actor, User $person): bool
    {
        return ! $actor->is($person) && $actor->isActive() && $actor->hasPermission(Permission::ApplyCorrections);
    }

    public function canPropose(User $actor, User $person): bool
    {
        if ($actor->is($person) || ! $actor->isActive() || ! $actor->hasPermission(Permission::ProposeCorrections)) {
            return false;
        }

        // Project Managers and Project Directors: the same "anyone" as their overtime approvals
        if ($actor->hasPermission(Permission::ApproveAnyOvertime)) {
            return true;
        }

        return $this->ledPeople($actor)->whereKey($person->id)->exists();
    }

    /** Apply for Superadmin, propose for Management. */
    public function canCorrect(User $actor, User $person): bool
    {
        return $this->canApply($actor, $person) || $this->canPropose($actor, $person);
    }

    /** @return Builder<User> everyone the actor may correct or propose a correction for, left staff included */
    public function people(User $actor): Builder
    {
        $query = User::query()->whereKeyNot($actor->id);

        if (! $actor->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        if ($actor->hasPermission(Permission::ApplyCorrections)
            || ($actor->hasPermission(Permission::ProposeCorrections) && $actor->hasPermission(Permission::ApproveAnyOvertime))) {
            return $query;
        }

        if ($actor->hasPermission(Permission::ProposeCorrections)) {
            return $this->ledPeople($actor);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Corrections the actor may see on Koreksi: everyone's for Superadmin, their people's and their own proposals for
     * Management. Never corrections of the actor's own records.
     *
     * @return Builder<Correction>
     */
    public function corrections(User $actor): Builder
    {
        $query = Correction::query()->whereHas('shift', fn ($q) => $q->where('user_id', '!=', $actor->id));

        if (! $actor->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        if ($actor->hasPermission(Permission::ApplyCorrections)) {
            return $query;
        }

        if (! $actor->hasPermission(Permission::ProposeCorrections)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn ($q) => $q
            ->where('proposed_by', $actor->id)
            ->orWhereHas('shift', fn ($s) => $s->whereIn('user_id', $this->people($actor)->select('users.id'))));
    }

    /** @return Builder<User> */
    private function ledPeople(User $lead): Builder
    {
        return User::query()
            ->whereKeyNot($lead->id)
            ->whereIn('users.id', DB::table('team_user')
                ->join('teams', 'teams.id', '=', 'team_user.team_id')
                ->where('teams.lead_user_id', $lead->id)
                ->select('team_user.user_id'))
            // A person who leads a team goes to Project Managers and Project Directors (3.4.4)
            ->whereNotIn('users.id', Team::query()->whereNotNull('lead_user_id')->select('lead_user_id'));
    }
}
