<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Calculation\IdlePeriodResult;
use App\Modules\Attendance\Calculation\ShiftResult;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;

/** A shift row together with its state calculated for a given moment. */
final readonly class ResolvedShift
{
    public function __construct(
        public Shift $shift,
        public ShiftResult $result,
        public int $regularBeforeMinutes,
        public bool $isWorkday,
        public bool $isShort,
    ) {}

    /** @return array<string, mixed> an overtime still waiting for its work report (3.4.2) */
    public function reportDueArray(): array
    {
        return [
            'shift_id' => $this->shift->id,
            'work_date' => $this->shift->work_date,
            'status' => $this->result->status->value,
            'overtime_started_at' => Time::iso($this->result->overtime?->startedAt),
            'overtime_ended_at' => Time::iso($this->result->overtime?->endedAt),
            'overtime_minutes' => $this->result->overtimeMinutes,
            'overtime_reason' => $this->result->overtime?->reason,
        ];
    }

    /** @return array<string, mixed> a shift the rules ended that can still get a late claim (3.3.6, 3.5.5) */
    public function lateClaimArray(): array
    {
        return [
            'shift_id' => $this->shift->id,
            'work_date' => $this->shift->work_date,
            'end_reason' => $this->result->endReason?->value,
            'overtime_end_reason' => $this->result->overtimeEndReason?->value,
            'auto_ended_at' => Time::iso($this->result->clockOutAt),
            'claimable_until' => Time::iso($this->result->claimableUntil),
            'latest_end_at' => Time::iso($this->result->latestClaimEndAt),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(?string $overtimeStatus = null): array
    {
        $r = $this->result;

        return [
            'id' => $this->shift->id,
            'work_date' => $this->shift->work_date,
            'is_workday' => $this->isWorkday,
            'status' => $r->status->value,
            'device_id' => $r->deviceId,
            'clock_in_at' => Time::iso($r->clockInAt),
            'clock_out_at' => Time::iso($r->clockOutAt),
            'regular_ends_at' => Time::iso($r->regularEndsAt),
            'prompt_deadline_at' => Time::iso($r->promptDeadlineAt),
            'last_seen_at' => Time::iso($r->lastSeenAt),
            'regular_limit_minutes' => $this->shift->regular_limit_minutes,
            'regular_before_minutes' => $this->regularBeforeMinutes,
            'regular_minutes' => $r->regularMinutes,
            'overtime_minutes' => $r->overtimeMinutes,
            'idle_minutes' => $r->idleMinutes,
            'interruption_minutes' => $r->interruptionMinutes,
            'end_reason' => $r->endReason?->value,
            'overtime_end_reason' => $r->overtimeEndReason?->value,
            'is_short' => $this->isShort,
            // Overtime ended without a work report; can be true on a shift closed for review too (3.4.2)
            'report_due' => $r->reportDue(),
            'late_claim' => $r->claimableUntil === null ? null : [
                'claimable_until' => Time::iso($r->claimableUntil),
                'latest_end_at' => Time::iso($r->latestClaimEndAt),
            ],
            'flags' => array_map(fn ($flag) => $flag->value, $r->flags),
            'idle_periods' => array_map(fn (IdlePeriodResult $p) => [
                'started_at' => Time::iso($p->startedAt),
                'ended_at' => Time::iso($p->endedAt),
                'minutes' => $p->minutes,
                'tag' => $p->tag?->value,
                'note' => $p->note,
            ], $r->idlePeriods),
            'interruptions' => array_map(fn (array $gap) => [
                'started_at' => Time::iso($gap[0]),
                'ended_at' => Time::iso($gap[1]),
            ], $r->interruptions),
            'overtime' => $r->overtime === null ? null : [
                'started_at' => Time::iso($r->overtime->startedAt),
                'ended_at' => Time::iso($r->overtime->endedAt),
                'minutes' => $r->overtime->minutes,
                'reason' => $r->overtime->reason,
                'work_report' => $r->overtime->workReport,
                'report_due' => $r->overtime->workReport === null,
                'is_late_claim' => $r->overtime->isLateClaim,
                'status' => $overtimeStatus,
            ],
        ];
    }
}
