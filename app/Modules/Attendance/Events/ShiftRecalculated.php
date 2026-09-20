<?php

namespace App\Modules\Attendance\Events;

use App\Modules\Attendance\Calculation\ShiftResult;
use App\Modules\Attendance\Models\Shift;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired after a shift was saved from its events. Overtime listens and keeps the overtime request in step. */
final class ShiftRecalculated
{
    use Dispatchable;

    public function __construct(
        public readonly Shift $shift,
        public readonly ShiftResult $result,
    ) {}
}
