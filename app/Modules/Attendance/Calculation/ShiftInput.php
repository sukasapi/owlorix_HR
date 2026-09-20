<?php

namespace App\Modules\Attendance\Calculation;

use Carbon\CarbonImmutable;

final readonly class ShiftInput
{
    /**
     * @param  list<ShiftEvent>  $events  events linked to the shift after the clock-in, any order
     * @param  CarbonImmutable|null  $nextClockInAt  clock-in of the person's next shift; this shift cannot run past it
     */
    public function __construct(
        public ShiftEvent $clockIn,
        public array $events,
        public bool $isWorkday,
        public int $regularLimitMinutes,
        public int $regularBeforeMinutes,
        public CarbonImmutable $lastSeenAt,
        public CarbonImmutable $now,
        public ?CarbonImmutable $nextClockInAt = null,
        public ShiftRules $rules = new ShiftRules,
    ) {}
}
