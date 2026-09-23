<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Calculation\IdlePeriodResult;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Services\LeaveDays;
use App\Modules\Organization\Models\Team;
use Carbon\CarbonImmutable;

/**
 * Data for Tim hari ini: the state of each person in the viewer's scope right now, read through ShiftStateResolver
 * so time rules apply without waiting for `attendance:settle`.
 *
 * Scope: people who approve anyone's overtime (Project Manager, Project Director) see every active person; a Team
 * Lead sees the members of the teams they lead. The viewer is left out; their own day is on Hari ini.
 *
 * A person with approved leave today who has not clocked in shows as on leave (docs/14 4.3). Clocking in on a
 * leave day is recorded as usual, and they then show by their shift like anyone else.
 */
class TeamBoard
{
    /** Board order (docs/01 4.2.6): needs an answer, overtime, clocked in, PC quiet, out, on leave, not started. */
    public const GROUPS = ['attention', 'overtime', 'working', 'idle', 'out', 'leave', 'not_started'];

    public function __construct(
        private readonly ShiftStateResolver $resolver,
        private readonly LeaveDays $leaveDays,
    ) {}

    /**
     * @return array{
     *     scope: array{everyone: bool, leads_team: bool},
     *     board: array{date: string, generated_at: string, teams: list<array{id: int, name: string}>, people: list<array<string, mixed>>},
     * }
     */
    public function for(User $viewer, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $today = Time::workDate($now);
        $everyone = $viewer->hasPermission(Permission::ApproveAnyOvertime);
        $leadsTeam = Team::query()->where('lead_user_id', $viewer->id)->exists();

        $people = User::query()
            ->active()
            ->whereKeyNot($viewer->id)
            ->when(! $everyone, fn ($q) => $q->whereHas('teams', fn ($t) => $t->where('lead_user_id', $viewer->id)))
            ->with(['teams' => fn ($q) => $q->select('teams.id', 'teams.name', 'teams.lead_user_id')->orderBy('name')])
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'username']);

        $ids = $people->modelKeys();
        $onLeave = $this->leaveDays->onDate($ids, $today);

        $withShiftsToday = Shift::query()
            ->whereIn('user_id', $ids)
            ->where(fn ($q) => $q->where('work_date', $today)->orWhereNull('clock_out_at'))
            ->distinct()
            ->pluck('user_id')
            ->flip();

        $unclosed = Shift::query()
            ->whereIn('user_id', $withShiftsToday->keys())
            ->whereNull('clock_out_at')
            ->where('work_date', '<', $today)
            ->get()
            ->keyBy('user_id');

        $rows = $people->map(function (User $person) use ($withShiftsToday, $unclosed, $onLeave, $today, $now) {
            $leave = $onLeave[$person->id] ?? null;

            if (! $withShiftsToday->has($person->id)) {
                return $this->row($person, [], null, $today, $leave);
            }

            $shifts = $this->resolver->workDate($person->id, $today, $now);
            $carried = $unclosed->get($person->id);
            $carriedResolved = $carried !== null ? $this->resolver->shift($carried, $now) : null;

            return $this->row($person, $shifts, $carriedResolved?->result->isLive() ? $carriedResolved : null, $today, $leave);
        });

        $hostnames = Device::query()->whereIn('id', $rows->pluck('device')->filter()->unique())->pluck('hostname', 'id');

        $teams = $everyone
            ? Team::query()->whereHas('members', fn ($q) => $q->whereIn('users.id', $ids))->orderBy('name')->get(['id', 'name'])
            : Team::query()->where('lead_user_id', $viewer->id)->orderBy('name')->get(['id', 'name']);

        return [
            'scope' => ['everyone' => $everyone, 'leads_team' => $leadsTeam],
            'board' => [
                'date' => $today,
                'generated_at' => Time::iso($now),
                'teams' => $teams->map(fn (Team $t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
                'people' => $rows
                    ->map(fn (array $row) => [...$row, 'device' => $row['device'] !== null ? ($hostnames[$row['device']] ?? null) : null])
                    // Stable sort: people keep their name order inside each group
                    ->sortBy(fn (array $row) => array_search($row['group'], self::GROUPS, true))
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @param  list<ResolvedShift>  $shifts  today's shifts
     * @param  array{request_id: int, type: string}|null  $leave  approved leave today
     * @return array<string, mixed>
     */
    private function row(User $person, array $shifts, ?ResolvedShift $carried, string $today, ?array $leave = null): array
    {
        $all = $carried !== null ? [$carried, ...$shifts] : $shifts;

        $live = null;
        $latest = null;

        foreach ($all as $resolved) {
            $latest = $resolved;

            if ($resolved->result->isLive()) {
                $live = $resolved;
            }
        }

        $reportDue = collect($all)->first(fn (ResolvedShift $r) => $r->result->reportDue());
        $openIdle = $live !== null ? $this->openIdle($live) : null;

        [$group, $status, $since, $eyes] = match (true) {
            $live?->result->status === ShiftStatus::Prompted => ['attention', 'prompted', $live->result->regularEndsAt ?? $live->result->clockInAt, 'attention'],
            $reportDue !== null => ['attention', 'report_due', $reportDue->result->overtime?->endedAt ?? $reportDue->result->clockOutAt, 'attention'],
            $live?->result->status === ShiftStatus::Overtime => ['overtime', 'overtime', $live->result->overtime?->startedAt ?? $live->result->clockInAt, $openIdle !== null ? 'half' : 'open'],
            $live?->result->status === ShiftStatus::Interrupted => ['idle', 'interrupted', $live->result->lastSeenAt, 'half'],
            $live !== null && $openIdle !== null => ['idle', 'idle', $openIdle->startedAt, 'half'],
            $live !== null => ['working', 'open', $live->result->clockInAt, 'open'],
            $latest !== null => ['out', 'out', $latest->result->clockOutAt, 'closed'],
            $leave !== null => ['leave', 'leave', null, 'closed'],
            default => ['not_started', 'not_started', null, 'closed'],
        };

        $todays = array_filter($all, fn (ResolvedShift $r) => $r->shift->work_date === $today);

        return [
            'id' => $person->id,
            'name' => $person->name,
            'initials' => $person->initials(),
            'team_ids' => $person->teams->modelKeys(),
            'teams' => $person->teams->pluck('name')->all(),
            'group' => $group,
            'status' => $status,
            'eyes' => $eyes,
            'since' => Time::iso($since),
            'device' => $live?->result->deviceId,
            'regular_minutes' => array_sum(array_map(fn (ResolvedShift $r) => $r->result->regularMinutes, $todays)),
            'overtime_minutes' => array_sum(array_map(fn (ResolvedShift $r) => $r->result->overtimeMinutes, $all)),
            'idle' => $openIdle === null ? null : [
                'started_at' => Time::iso($openIdle->startedAt),
                'minutes' => $openIdle->minutes,
                'tag' => $openIdle->tag?->value,
            ],
            'needs_review' => collect($all)->contains(fn (ResolvedShift $r) => $r->result->status === ShiftStatus::NeedsReview),
            'leave' => $leave === null ? null : ['type' => $leave['type']],
        ];
    }

    /** The quiet period still running on a live shift: no end yet. */
    private function openIdle(ResolvedShift $live): ?IdlePeriodResult
    {
        $open = array_filter($live->result->idlePeriods, fn (IdlePeriodResult $p) => $p->endedAt === null);

        return $open === [] ? null : end($open);
    }
}
