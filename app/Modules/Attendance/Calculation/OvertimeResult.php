<?php

namespace App\Modules\Attendance\Calculation;

use Carbon\CarbonImmutable;

final readonly class OvertimeResult
{
    public function __construct(
        public CarbonImmutable $startedAt,
        public ?CarbonImmutable $endedAt,
        public int $minutes,
        public ?string $reason,
        public ?string $workReport,
        public ?CarbonImmutable $reportSubmittedAt,
        public bool $isLateClaim,
    ) {}
}
