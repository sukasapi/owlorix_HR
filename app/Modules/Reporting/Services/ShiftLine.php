<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Attendance\Enums\ShiftFlag;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ResolvedShift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRequest;
use Carbon\CarbonImmutable;

/** One shift as the report reads it: saved values, or the state right now for a shift that is still running. */
final readonly class ShiftLine
{
    /** Notes in the order the page and the export list them. */
    public const NOTES = ['running', 'needs_review', 'clock_mismatch', 'gap_unverified', 'late_claim', 'offline_sign_in', 'short', 'report_due'];

    /** @param list<string> $flags ShiftFlag values */
    public function __construct(
        public int $shiftId,
        public int $userId,
        public string $workDate,
        public bool $isWorkday,
        public ShiftStatus $status,
        public CarbonImmutable $clockInAt,
        public ?CarbonImmutable $clockOutAt,
        public int $regularMinutes,
        public int $overtimeMinutes,
        public ?OvertimeStatus $overtimeStatus,
        public int $idleMinutes,
        public int $interruptionMinutes,
        public bool $isShort,
        public bool $reportDue,
        public array $flags,
    ) {}

    public static function fromSaved(Shift $shift, ?OvertimeRequest $request): self
    {
        return new self(
            shiftId: $shift->id,
            userId: $shift->user_id,
            workDate: $shift->work_date,
            isWorkday: $shift->is_workday,
            status: $shift->status,
            clockInAt: $shift->clock_in_at->utc(),
            clockOutAt: $shift->clock_out_at?->utc(),
            regularMinutes: $shift->regular_minutes,
            overtimeMinutes: $shift->overtime_minutes,
            overtimeStatus: self::overtimeStatus($shift->overtime_minutes, $request),
            idleMinutes: $shift->idle_minutes,
            interruptionMinutes: $shift->interruption_minutes,
            isShort: $shift->is_short,
            reportDue: $shift->clock_out_at !== null && $request !== null && $request->work_report === null,
            flags: array_values($shift->flags ?? []),
        );
    }

    public static function fromResolved(ResolvedShift $resolved, ?OvertimeRequest $request): self
    {
        $r = $resolved->result;

        return new self(
            shiftId: $resolved->shift->id,
            userId: $resolved->shift->user_id,
            workDate: $resolved->shift->work_date,
            isWorkday: $resolved->isWorkday,
            status: $r->status,
            clockInAt: $r->clockInAt->utc(),
            clockOutAt: $r->clockOutAt?->utc(),
            regularMinutes: $r->regularMinutes,
            overtimeMinutes: $r->overtimeMinutes,
            overtimeStatus: self::overtimeStatus($r->overtimeMinutes, $request),
            idleMinutes: $r->idleMinutes,
            interruptionMinutes: $r->interruptionMinutes,
            isShort: $resolved->isShort,
            reportDue: $r->reportDue(),
            flags: array_map(fn (ShiftFlag $flag) => $flag->value, $r->flags),
        );
    }

    /** Overtime without a request yet has not been decided, so it counts as pending. */
    private static function overtimeStatus(int $minutes, ?OvertimeRequest $request): ?OvertimeStatus
    {
        return $minutes > 0 ? ($request?->status ?? OvertimeStatus::Pending) : null;
    }

    public function isRunning(): bool
    {
        return $this->clockOutAt === null;
    }

    /** Closed for review (3.7.3), or flagged for a check: PC clock (3.9.4) or a gap the server could not verify (I6). */
    public function needsReview(): bool
    {
        return $this->status === ShiftStatus::NeedsReview
            || $this->hasFlag(ShiftFlag::ClockMismatch)
            || $this->hasFlag(ShiftFlag::GapUnverified);
    }

    public function isLateClaim(): bool
    {
        return $this->hasFlag(ShiftFlag::LateClaim);
    }

    public function hasFlag(ShiftFlag $flag): bool
    {
        return in_array($flag->value, $this->flags, true);
    }

    /** @return list<string> keys from NOTES */
    public function notes(): array
    {
        $present = [
            'running' => $this->isRunning(),
            'needs_review' => $this->status === ShiftStatus::NeedsReview,
            'clock_mismatch' => $this->hasFlag(ShiftFlag::ClockMismatch),
            'gap_unverified' => $this->hasFlag(ShiftFlag::GapUnverified),
            'late_claim' => $this->isLateClaim(),
            'offline_sign_in' => $this->hasFlag(ShiftFlag::OfflineSignIn),
            'short' => $this->isShort,
            'report_due' => $this->reportDue,
        ];

        return array_values(array_filter(self::NOTES, fn (string $note) => $present[$note]));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->shiftId,
            'work_date' => $this->workDate,
            'is_workday' => $this->isWorkday,
            'clock_in_at' => Time::iso($this->clockInAt),
            'clock_out_at' => Time::iso($this->clockOutAt),
            'regular_minutes' => $this->regularMinutes,
            'overtime_minutes' => $this->overtimeMinutes,
            'overtime_status' => $this->overtimeStatus?->value,
            'idle_minutes' => $this->idleMinutes,
            'interruption_minutes' => $this->interruptionMinutes,
            'notes' => $this->notes(),
        ];
    }
}
