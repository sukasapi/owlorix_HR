<?php

namespace App\Modules\Attendance\Calculation;

use App\Modules\Attendance\Enums\IdleTag;
use Carbon\CarbonImmutable;

final readonly class IdlePeriodResult
{
    public function __construct(
        public CarbonImmutable $startedAt,
        public ?CarbonImmutable $endedAt,
        public int $minutes,
        public ?IdleTag $tag = null,
        public ?string $note = null,
    ) {}
}
