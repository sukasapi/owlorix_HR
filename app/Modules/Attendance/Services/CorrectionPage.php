<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\CorrectionField;
use App\Modules\Attendance\Enums\CorrectionStatus;
use App\Modules\Attendance\Models\Correction;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Overtime\Models\OvertimeRequest;
use Carbon\CarbonImmutable;

/**
 * Data for Koreksi (3.10): proposals waiting for Superadmin, oldest first, and decided corrections, newest first, in
 * the viewer's scope; the people the viewer may correct; and, for the correction form, one person's shifts on a work
 * date with the times that can be corrected.
 */
class CorrectionPage
{
    public const HISTORY_LIMIT = 50;

    public const STATUSES = ['all', 'applied', 'declined'];

    public function __construct(
        private readonly CorrectionScope $scope,
        private readonly ShiftStateResolver $resolver,
    ) {}

    /**
     * @param  array{status: string, person: int|null}  $filters
     * @return array<string, mixed>
     */
    public function build(User $viewer, array $filters, CarbonImmutable $now): array
    {
        $visible = fn () => $this->scope->corrections($viewer)
            ->with([
                'shift:id,user_id,work_date',
                'shift.user' => fn ($q) => $q->select('id', 'name', 'username', 'status'),
                'proposer:id,name',
                'approver:id,name',
            ])
            ->when($filters['person'] !== null, fn ($q) => $q->whereHas('shift', fn ($s) => $s->where('user_id', $filters['person'])));

        $waiting = $visible()->where('status', CorrectionStatus::Proposed)->orderBy('created_at')->orderBy('id')->get();

        $history = $visible()
            ->when($filters['status'] === 'all', fn ($q) => $q->where('status', '!=', CorrectionStatus::Proposed))
            ->when($filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        $canApply = $viewer->isActive() && $viewer->hasPermission(Permission::ApplyCorrections);
        $currentMonth = substr(Time::workDate($now), 0, 7);

        return [
            'abilities' => [
                'apply' => $canApply,
                'propose' => $viewer->isActive() && $viewer->hasPermission(Permission::ProposeCorrections),
            ],
            'filters' => $filters,
            'waiting' => $waiting->map(fn (Correction $c) => $this->item($c, $viewer, $currentMonth, $now, withCurrent: $canApply))->values()->all(),
            'history' => $history->map(fn (Correction $c) => $this->item($c, $viewer, $currentMonth, $now, withCurrent: false))->values()->all(),
            'history_limit' => self::HISTORY_LIMIT,
            'people' => $this->scope->people($viewer)
                ->with(['teams' => fn ($q) => $q->select('teams.id', 'teams.name')->orderBy('name')])
                ->orderBy('name')
                ->orderBy('id')
                ->get(['users.id', 'users.name', 'users.username', 'users.status'])
                ->map(fn (User $person) => [
                    'id' => $person->id,
                    'name' => $person->name,
                    'initials' => $person->initials(),
                    'status' => $person->status->value,
                    'teams' => $person->teams->pluck('name')->all(),
                ])->values()->all(),
            'today' => Time::workDate($now),
        ];
    }

    /**
     * The person's shifts on one work date, for the correction form.
     *
     * @return array<string, mixed>
     */
    public function shifts(User $person, string $workDate, CarbonImmutable $now): array
    {
        $resolved = $this->resolver->workDate($person->id, $workDate, $now);
        $requests = OvertimeRequest::query()
            ->whereIn('shift_id', array_map(fn (ResolvedShift $r) => $r->shift->id, $resolved))
            ->pluck('status', 'shift_id');

        return [
            'person_id' => $person->id,
            'work_date' => $workDate,
            'is_workday' => $resolved[0]->isWorkday ?? null,
            'closed_month' => substr($workDate, 0, 7) < substr(Time::workDate($now), 0, 7),
            'shifts' => array_map(function (ResolvedShift $item) use ($requests) {
                $r = $item->result;
                $live = $r->isLive();

                return [
                    'id' => $item->shift->id,
                    'status' => $r->status->value,
                    'is_live' => $live,
                    'is_workday' => $item->isWorkday,
                    'regular_minutes' => $r->regularMinutes,
                    'overtime_minutes' => $r->overtimeMinutes,
                    'overtime_status' => $requests->get($item->shift->id)?->value,
                    'values' => [
                        CorrectionField::ClockIn->value => Time::iso($r->clockInAt),
                        CorrectionField::ClockOut->value => Time::iso($r->clockOutAt),
                        CorrectionField::OvertimeStart->value => Time::iso($r->overtime?->startedAt),
                        CorrectionField::OvertimeEnd->value => Time::iso($r->overtime?->endedAt),
                    ],
                    // Why a time cannot be corrected on this shift; the server checks the same in CorrectionPreview
                    'unavailable' => array_filter([
                        CorrectionField::ClockIn->value => $live ? 'running' : null,
                        CorrectionField::ClockOut->value => $live ? 'running' : null,
                        CorrectionField::OvertimeStart->value => $live ? 'running' : (! $item->isWorkday ? 'non_workday' : null),
                        CorrectionField::OvertimeEnd->value => $live ? 'running' : ($r->overtime === null ? 'no_overtime' : null),
                    ]),
                ];
            }, $resolved),
        ];
    }

    /** @return array<string, mixed> */
    private function item(Correction $correction, User $viewer, string $currentMonth, CarbonImmutable $now, bool $withCurrent): array
    {
        $shift = $correction->shift;
        $person = $shift?->user;
        $current = null;

        // What the shift shows now, so the Superadmin sees when it changed after the proposal
        if ($withCurrent && $shift !== null) {
            $resolved = $this->resolver->shift($shift, $now);
            $current = $resolved !== null ? Time::iso(CorrectionOutcome::valueOf($resolved->result, $correction->field)) : null;
        }

        return [
            'id' => $correction->id,
            'status' => $correction->status->value,
            'field' => $correction->field->value,
            'shift_id' => $correction->shift_id,
            'work_date' => $shift?->work_date,
            'closed_month' => $shift !== null && substr($shift->work_date, 0, 7) < $currentMonth,
            'person' => [
                'id' => $person?->id,
                'name' => $person?->name,
                'initials' => $person?->initials(),
                'status' => $person?->status->value,
            ],
            'old_value' => $correction->old_value,
            'new_value' => $correction->new_value,
            'current_value' => $current,
            'changed_since' => $withCurrent && $current !== $correction->old_value,
            'reason' => $correction->reason,
            'proposed_by' => $correction->proposer?->name,
            'proposed_at' => Time::iso($correction->created_at),
            'decided_by' => $correction->approver?->name,
            'decided_at' => Time::iso($correction->decided_at),
            'decision_note' => $correction->decision_note,
            'is_direct' => $correction->isDirect(),
            'is_mine' => $correction->proposed_by === $viewer->id,
            'can_decide' => $correction->status === CorrectionStatus::Proposed && $person !== null && $this->scope->canApply($viewer, $person),
        ];
    }
}
