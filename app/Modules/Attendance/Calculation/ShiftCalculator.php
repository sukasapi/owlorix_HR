<?php

namespace App\Modules\Attendance\Calculation;

/**
 * Rebuilds one shift from its events (docs/02-attendance-rules.md). Pure: no database, no clock;
 * the same input always gives the same result, so time-based states never depend on when cron runs.
 */
final class ShiftCalculator
{
    public function calculate(ShiftInput $input): ShiftResult
    {
        return (new ShiftWalk($input))->run();
    }
}
