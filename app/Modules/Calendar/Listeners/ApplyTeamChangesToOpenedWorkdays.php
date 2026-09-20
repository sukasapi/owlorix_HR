<?php

namespace App\Modules\Calendar\Listeners;

use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Calendar\Events\CalendarDatesChanged;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Organization\Events\TeamMembersChanged;
use App\Modules\Shared\Audit\Auditor;

/**
 * A workday opened for a team covers whoever is in the team, so joining or leaving changes
 * the workday status of those people on the team's opened dates. A deleted team's opened
 * dates are closed with it.
 */
class ApplyTeamChangesToOpenedWorkdays
{
    public function __construct(private readonly Auditor $auditor) {}

    public function handle(TeamMembersChanged $event): void
    {
        $opened = OpenedWorkday::query()
            ->where('scope_type', OpenedScope::Team)
            ->where('scope_id', $event->teamId)
            ->get();

        if ($opened->isEmpty()) {
            return;
        }

        if ($event->teamDeleted) {
            foreach ($opened as $day) {
                $day->delete();
                $this->auditor->record('calendar.opened_workday.closed_with_team', $day, ['date' => $day->date->toDateString(), 'team_id' => $event->teamId], null);
            }
        }

        if ($event->userIds !== []) {
            CalendarDatesChanged::dispatch(
                $opened->map(fn (OpenedWorkday $d) => $d->date->toDateString())->unique()->sort()->values()->all(),
                $event->userIds,
            );
        }
    }
}
