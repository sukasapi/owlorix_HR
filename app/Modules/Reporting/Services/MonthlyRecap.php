<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftStateResolver;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Services\LeaveDays;
use App\Modules\Organization\Models\Team;
use App\Modules\Overtime\Services\OvertimeLookup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The numbers behind Laporan and its Excel export, built in one place so both always match.
 *
 * A shift belongs to the month of its work date (the Asia/Jakarta date of its clock-in). Saved shift rows are used
 * as they are, except work dates that still have a shift without a clock-out: those are resolved for this moment
 * with ShiftStateResolver, the same reading as Hari ini.
 */
class MonthlyRecap
{
    public function __construct(
        private readonly ReportScope $scope,
        private readonly ShiftStateResolver $resolver,
        private readonly OvertimeLookup $overtime,
        private readonly LeaveDays $leaveDays,
    ) {}

    /** @param int|null $teamId a team outside the viewer's scope is ignored */
    public function build(User $viewer, ReportMonth $month, ?int $teamId = null, ?CarbonImmutable $now = null): Recap
    {
        $now ??= CarbonImmutable::now();
        $everyone = $this->scope->seesEveryone($viewer);
        $teams = $this->scope->teams($viewer);
        $team = $teamId !== null ? $teams->firstWhere('id', $teamId) : null;

        if (! $this->scope->canView($viewer)) {
            return new Recap($month, false, null, collect(), [], [], new Totals, $now);
        }

        $candidateIds = match (true) {
            $team !== null => $team->members->pluck('id')->all(),
            $everyone => null,
            default => $teams->flatMap(fn (Team $t) => $t->members->pluck('id'))->unique()->values()->all(),
        };

        $lines = $candidateIds === [] ? collect() : $this->lines($candidateIds, $month, $now);
        $teamNames = $this->teamNamesByUser($teams);

        $users = ($candidateIds === [] ? collect() : User::withTrashed()
            ->when($candidateIds !== null, fn ($q) => $q->whereIn('id', $candidateIds))
            ->orderBy('name')
            ->orderBy('id')
            ->get())
            ->filter(fn (User $user) => $lines->has($user->id) || $this->expectedInMonth($user, $month));

        $leave = $this->leave($users->map(fn (User $user) => $user->id)->values()->all(), $month, $now);

        $rows = $users
            ->map(fn (User $user) => new PersonRow(
                $user,
                $teamNames[$user->id] ?? [],
                Totals::forPerson($lines->get($user->id, []), count($leave[$user->id] ?? [])),
                $lines->get($user->id, []),
            ))
            ->keyBy(fn (PersonRow $row) => $row->user->id);

        $groups = [];

        foreach ($team !== null ? [$team] : $teams as $groupTeam) {
            $memberIds = $groupTeam->members->pluck('id')->flip();
            $people = $rows->filter(fn (PersonRow $row) => $memberIds->has($row->user->id))->values()->all();

            if ($people !== []) {
                $groups[] = $this->group(['id' => $groupTeam->id, 'name' => $groupTeam->name], $people);
            }
        }

        if ($team === null && $everyone) {
            $teamless = $rows->filter(fn (PersonRow $row) => $row->teamNames === [])->values()->all();

            if ($teamless !== []) {
                $groups[] = $this->group(null, $teamless);
            }
        }

        $shown = collect($groups)->flatMap(fn (array $g) => array_map(fn (PersonRow $row) => $row->user->id, $g['people']))->flip();
        $people = $rows->filter(fn (PersonRow $row) => $shown->has($row->user->id))->values()->all();

        return new Recap(
            month: $month,
            seesEveryone: $everyone,
            team: $team,
            teams: $teams,
            groups: $groups,
            people: $people,
            total: Totals::sum(array_map(fn (PersonRow $row) => $row->totals, $people)),
            generatedAt: $now,
        );
    }

    /** One person's month, or null when they are outside the viewer's scope. */
    public function person(User $viewer, ReportMonth $month, int $userId, ?CarbonImmutable $now = null): ?PersonRow
    {
        $now ??= CarbonImmutable::now();

        if (! $this->scope->includes($viewer, $userId)) {
            return null;
        }

        $user = User::withTrashed()->find($userId);

        if ($user === null) {
            return null;
        }

        $lines = $this->lines([$userId], $month, $now)->get($userId, []);
        $leave = $this->leave([$userId], $month, $now)[$userId] ?? [];

        return new PersonRow($user, $this->teamNamesByUser($this->scope->teams($viewer))[$userId] ?? [], Totals::forPerson($lines, count($leave)), $lines);
    }

    /**
     * @param  list<int>|null  $userIds  null for everyone
     * @return Collection<int, list<ShiftLine>> keyed by user id, each list in clock-in order
     */
    private function lines(?array $userIds, ReportMonth $month, CarbonImmutable $now): Collection
    {
        $shifts = Shift::query()
            ->whereBetween('work_date', [$month->firstDate(), $month->lastDate()])
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->orderBy('clock_in_at')
            ->orderBy('id')
            ->get();

        $resolved = [];
        $liveDates = [];

        // A running shift's saved values lag the time rules; its whole work date is read for this moment instead
        foreach ($shifts->whereNull('clock_out_at') as $open) {
            $key = $open->user_id.'|'.$open->work_date;

            if (isset($liveDates[$key])) {
                continue;
            }

            $liveDates[$key] = true;

            foreach ($this->resolver->workDate($open->user_id, $open->work_date, $now) as $shift) {
                $resolved[$shift->shift->id] = $shift;
            }
        }

        $requests = $this->overtime->forShifts($shifts->modelKeys());
        $lines = [];

        foreach ($shifts as $shift) {
            if (! isset($liveDates[$shift->user_id.'|'.$shift->work_date])) {
                $lines[$shift->user_id][] = ShiftLine::fromSaved($shift, $requests[$shift->id] ?? null);
            } elseif (isset($resolved[$shift->id])) {
                // Missing from the resolved date means an undone clock-in (3.1.2), which is out of the totals
                $lines[$shift->user_id][] = ShiftLine::fromResolved($resolved[$shift->id], $requests[$shift->id] ?? null);
            }
        }

        return collect($lines);
    }

    /**
     * Approved leave days counted like attendance: up to today (studio date) in the running month, none in a month
     * still to come.
     *
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    private function leave(array $userIds, ReportMonth $month, CarbonImmutable $now): array
    {
        if ($month->isFuture($now)) {
            return [];
        }

        $until = $month->isCurrent($now) ? Time::workDate($now) : $month->lastDate();

        return $this->leaveDays->approvedDates($userIds, $month->firstDate(), $until);
    }

    /** Active accounts that existed during the month are listed even without shifts, so a missing month shows. */
    private function expectedInMonth(User $user, ReportMonth $month): bool
    {
        return ! $user->trashed()
            && $user->isActive()
            && ($user->created_at === null || $user->created_at->lessThanOrEqualTo($month->endsAt()));
    }

    /**
     * @param  Collection<int, Team>  $teams
     * @return array<int, list<string>>
     */
    private function teamNamesByUser(Collection $teams): array
    {
        $names = [];

        foreach ($teams as $team) {
            foreach ($team->members as $member) {
                $names[$member->id][] = $team->name;
            }
        }

        return $names;
    }

    /**
     * @param  array{id: int, name: string}|null  $team
     * @param  list<PersonRow>  $people
     * @return array{team: array{id: int, name: string}|null, people: list<PersonRow>, subtotal: Totals}
     */
    private function group(?array $team, array $people): array
    {
        return [
            'team' => $team,
            'people' => $people,
            'subtotal' => Totals::sum(array_map(fn (PersonRow $row) => $row->totals, $people)),
        ];
    }
}
