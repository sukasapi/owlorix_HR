<?php

namespace App\Modules\Organization\Actions;

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Events\TeamMembersChanged;
use App\Modules\Organization\Models\Team;
use App\Modules\Shared\Audit\Auditor;

/**
 * Team membership changes from both places that edit it: the person dialog and the team page.
 * A Team Lead must be a member holding the team_lead role, so every change that breaks that
 * clears the lead and writes the reason to the audit log.
 */
class TeamMembership
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  list<int>  $teamIds
     * @return array{added: list<int>, removed: list<int>}
     */
    public function syncForPerson(User $user, array $teamIds): array
    {
        $current = $user->teams()->pluck('teams.id')->map(fn ($id) => (int) $id)->all();
        $wanted = array_values(array_unique(array_map('intval', $teamIds)));

        $added = array_values(array_diff($wanted, $current));
        $removed = array_values(array_diff($current, $wanted));

        if ($added !== []) {
            $user->teams()->attach($added, ['joined_at' => $this->today()]);
        }

        if ($removed !== []) {
            $user->teams()->detach($removed);
            Team::query()->whereIn('id', $removed)->where('lead_user_id', $user->id)->get()
                ->each(fn (Team $team) => $this->clearLead($team, 'member_removed'));
        }

        foreach ([...$added, ...$removed] as $teamId) {
            TeamMembersChanged::dispatch($teamId, [$user->id]);
        }

        sort($added);
        sort($removed);

        return ['added' => $added, 'removed' => $removed];
    }

    public function add(Team $team, User $user): void
    {
        $team->members()->syncWithoutDetaching([$user->id => ['joined_at' => $this->today()]]);

        $this->auditor->record('team.member_added', $team, null, ['user_id' => $user->id]);

        TeamMembersChanged::dispatch($team->id, [$user->id]);
    }

    public function remove(Team $team, User $user): void
    {
        $team->members()->detach($user->id);

        $this->auditor->record('team.member_removed', $team, ['user_id' => $user->id], null);

        TeamMembersChanged::dispatch($team->id, [$user->id]);

        if ((int) $team->lead_user_id === $user->id) {
            $this->clearLead($team, 'member_removed');
        }
    }

    /** Clears the lead of every team led by someone who no longer holds the team_lead role. */
    public function releaseLeadsWithoutRole(User $user): void
    {
        if ($user->hasRole(Role::TeamLead->value)) {
            return;
        }

        $user->ledTeams()->get()->each(fn (Team $team) => $this->clearLead($team, 'role_removed'));
    }

    private function clearLead(Team $team, string $reason): void
    {
        $before = $team->lead_user_id;
        $team->forceFill(['lead_user_id' => null])->save();

        $this->auditor->record('team.lead_changed', $team, ['lead_user_id' => $before], ['lead_user_id' => null, 'reason' => $reason]);
    }

    private function today(): string
    {
        return now(config('owlorix.display_timezone'))->toDateString();
    }
}
