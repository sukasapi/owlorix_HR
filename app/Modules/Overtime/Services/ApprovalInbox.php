<?php

namespace App\Modules\Overtime\Services;

use App\Modules\Attendance\Enums\ShiftFlag;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\IdlePeriod;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use App\Modules\Overtime\Models\OvertimeDecision;
use App\Modules\Overtime\Models\OvertimeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Data for the Persetujuan page: pending requests the viewer decides and recent decisions they can see, each with
 * what an approver needs to judge it (reason, report, quiet PC time during the overtime, flags) and whether a
 * decision is possible now, following the refusals of DecideOvertime.
 */
class ApprovalInbox
{
    public const DECIDED_LIMIT = 50;

    /** Flags that make an approver open the request on its own; bulk approval skips these. */
    public const ATTENTION_FLAGS = ['late_claim', 'needs_review', 'clock_mismatch', 'gap_unverified'];

    public function __construct(private readonly PendingApprovals $approvals) {}

    /** @return array<string, mixed> */
    public function for(User $viewer): array
    {
        $pending = $this->withDetails($this->approvals->decidableBy($viewer))
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();

        $decided = $this->withDetails($this->approvals->decidedVisibleTo($viewer))
            ->addSelect(['last_decided_at' => OvertimeDecision::query()
                ->select('decided_at')
                ->whereColumn('overtime_request_id', 'overtime_requests.id')
                ->orderByDesc('id')
                ->limit(1)])
            ->orderByDesc('last_decided_at')
            ->orderByDesc('id')
            ->limit(self::DECIDED_LIMIT)
            ->get();

        $canChange = $viewer->isActive() && $viewer->hasPermission(Permission::ChangeOvertimeDecisions);

        $all = $pending->concat($decided);
        $teamIds = $all->flatMap(fn (OvertimeRequest $r) => $r->user?->teams->modelKeys() ?? [])->unique();

        return [
            'abilities' => [
                'approve' => $viewer->isActive() && $viewer->hasPermission(Permission::ApproveOvertime),
                'change' => $canChange,
            ],
            'pending' => $pending->map(fn (OvertimeRequest $r) => $this->item($r, false))->values()->all(),
            'decided' => $decided->map(fn (OvertimeRequest $r) => $this->item($r, $canChange))->values()->all(),
            'decided_limit' => self::DECIDED_LIMIT,
            'teams' => Team::query()->whereIn('id', $teamIds)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Team $team) => ['id' => $team->id, 'name' => $team->name])->values()->all(),
        ];
    }

    /** @return list<string> */
    public function flags(OvertimeRequest $request): array
    {
        $shift = $request->shift;
        $shiftFlags = $shift?->flags ?? [];

        return array_values(array_filter([
            ($request->is_late_claim || in_array(ShiftFlag::LateClaim->value, $shiftFlags, true)) ? 'late_claim' : null,
            $shift?->status === ShiftStatus::NeedsReview ? 'needs_review' : null,
            in_array(ShiftFlag::ClockMismatch->value, $shiftFlags, true) ? 'clock_mismatch' : null,
            in_array(ShiftFlag::GapUnverified->value, $shiftFlags, true) ? 'gap_unverified' : null,
        ]));
    }

    /** Why a decision is refused right now (3.4.5), in the same order DecideOvertime checks it. */
    public function blocked(OvertimeRequest $request): ?string
    {
        if ($request->ended_at === null) {
            return 'running';
        }

        return $request->work_report === null ? 'report_due' : null;
    }

    /**
     * @param  Builder<OvertimeRequest>  $query
     * @return Builder<OvertimeRequest>
     */
    private function withDetails(Builder $query): Builder
    {
        return $query->select('overtime_requests.*')->with([
            'user' => fn ($q) => $q->select('id', 'name', 'username', 'status'),
            'user.teams' => fn ($q) => $q->select('teams.id', 'teams.name')->orderBy('name'),
            'shift' => fn ($q) => $q->select('id', 'user_id', 'work_date', 'status', 'flags', 'clock_in_at', 'clock_out_at', 'end_reason', 'overtime_end_reason'),
            'shift.idlePeriods',
            'latestDecision.decider' => fn ($q) => $q->select('id', 'name'),
        ]);
    }

    /** @return array<string, mixed> */
    private function item(OvertimeRequest $request, bool $canChange): array
    {
        $person = $request->user;
        $shift = $request->shift;
        $decision = $request->latestDecision;

        return [
            'id' => $request->id,
            'status' => $request->status->value,
            'person' => [
                'id' => $person?->id,
                'name' => $person?->name,
                'initials' => $person?->initials(),
                'status' => $person?->status?->value,
            ],
            'team_ids' => $person?->teams->modelKeys() ?? [],
            'teams' => $person?->teams->pluck('name')->all() ?? [],
            'work_date' => $shift?->work_date,
            'started_at' => Time::iso($request->started_at),
            'ended_at' => Time::iso($request->ended_at),
            'minutes' => $request->minutes,
            'reason' => $request->reason,
            'work_report' => $request->work_report,
            'submitted_at' => Time::iso($request->submitted_at),
            'end_reason' => $shift?->end_reason?->value,
            'overtime_end_reason' => $shift?->overtime_end_reason?->value,
            'flags' => $this->flags($request),
            'blocked' => $this->blocked($request),
            'idle' => $this->idleDuringOvertime($request, $shift?->idlePeriods ?? collect()),
            'decision' => $decision === null ? null : [
                'id' => $decision->id,
                'decision' => $decision->decision->value,
                'note' => $decision->note,
                'decided_at' => Time::iso($decision->decided_at),
                'decided_by' => $decision->decider?->name,
            ],
            'can_change' => $canChange && $person !== null && $this->blocked($request) === null,
        ];
    }

    /**
     * Quiet PC periods inside the overtime window, cut to that window. Shown to Management only (3.6.4).
     *
     * @param  Collection<int, IdlePeriod>  $periods
     * @return list<array{started_at: string|null, ended_at: string|null, minutes: int, tag: string|null}>
     */
    private function idleDuringOvertime(OvertimeRequest $request, Collection $periods): array
    {
        $from = $request->started_at;
        $until = $request->ended_at ?? CarbonImmutable::now();
        $rows = [];

        foreach ($periods as $period) {
            $start = $period->started_at->max($from);
            $end = ($period->ended_at ?? $until)->min($until);

            if ($end <= $start) {
                continue;
            }

            $rows[] = [
                'started_at' => Time::iso($start),
                'ended_at' => $period->ended_at === null && $request->ended_at === null ? null : Time::iso($end),
                'minutes' => intdiv((int) $start->diffInSeconds($end, true), 60),
                'tag' => $period->tag?->value,
            ];
        }

        return $rows;
    }
}
