<?php

namespace App\Modules\Attendance\Calculation;

use App\Modules\Attendance\Enums\EndReason;
use App\Modules\Attendance\Enums\OvertimeEndReason;
use App\Modules\Attendance\Enums\ShiftFlag;
use App\Modules\Attendance\Enums\ShiftStatus;
use Carbon\CarbonImmutable;

final readonly class ShiftResult
{
    /**
     * @param  list<IdlePeriodResult>  $idlePeriods
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>  $interruptions
     * @param  list<ShiftFlag>  $flags
     * @param  CarbonImmutable|null  $claimableUntil  end of the late claim window, while a claim is still possible
     * @param  CarbonImmutable|null  $latestClaimEndAt  latest end time a late claim may give: last recorded activity
     * @param  CarbonImmutable|null  $webPresenceCheckAt  when "Masih lembur?" is (or was) shown for overtime running in a browser
     * @param  CarbonImmutable|null  $webPresenceAnswerBy  when overtime in a browser ends without an answer to that check
     */
    public function __construct(
        public bool $cancelled,
        public ShiftStatus $status,
        public CarbonImmutable $clockInAt,
        public ?CarbonImmutable $clockOutAt,
        public ?CarbonImmutable $regularEndsAt,
        public ?CarbonImmutable $promptDeadlineAt,
        public ?EndReason $endReason,
        public ?OvertimeEndReason $overtimeEndReason,
        public int $regularMinutes,
        public int $overtimeMinutes,
        public int $idleMinutes,
        public int $interruptionMinutes,
        public array $idlePeriods,
        public array $interruptions,
        public ?OvertimeResult $overtime,
        public array $flags,
        public CarbonImmutable $lastSeenAt,
        public string $deviceId,
        public ?CarbonImmutable $claimableUntil = null,
        public ?CarbonImmutable $latestClaimEndAt = null,
        public ?CarbonImmutable $webPresenceCheckAt = null,
        public ?CarbonImmutable $webPresenceAnswerBy = null,
    ) {}

    public static function cancelled(ShiftEvent $clockIn, CarbonImmutable $cancelledAt): self
    {
        return new self(
            cancelled: true,
            status: ShiftStatus::Closed,
            clockInAt: $clockIn->occurredAt,
            clockOutAt: $cancelledAt,
            regularEndsAt: null,
            promptDeadlineAt: null,
            endReason: EndReason::Manual,
            overtimeEndReason: null,
            regularMinutes: 0,
            overtimeMinutes: 0,
            idleMinutes: 0,
            interruptionMinutes: 0,
            idlePeriods: [],
            interruptions: [],
            overtime: null,
            flags: [],
            lastSeenAt: $cancelledAt,
            deviceId: $clockIn->deviceId,
        );
    }

    public function isLive(): bool
    {
        return ! $this->cancelled && $this->clockOutAt === null;
    }

    public function hasFlag(ShiftFlag $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /** 3.4.2: overtime has ended and its work report is still missing. Independent of the status: a shift closed for review can have a report due too. */
    public function reportDue(): bool
    {
        return $this->overtime !== null && $this->overtime->workReport === null && ! $this->isLive() && ! $this->cancelled;
    }

    public function withRegularMinutes(int $minutes): self
    {
        return new self(
            ...[...get_object_vars($this), 'regularMinutes' => $minutes],
        );
    }
}
