<?php

namespace App\Modules\Attendance\Console;

use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftRecalculator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Saves time-based states of shifts without a clock-out (prompt timeout, presence check, resume window). */
class SettleShiftsCommand extends Command
{
    protected $signature = 'attendance:settle';

    protected $description = 'Save time-based states of running shifts';

    public function handle(ShiftRecalculator $recalculator): int
    {
        $now = CarbonImmutable::now();

        $dates = Shift::query()
            ->whereNull('clock_out_at')
            ->select(['user_id', 'work_date'])
            ->distinct()
            ->get();

        foreach ($dates as $row) {
            $recalculator->recalculateWorkDate($row->user_id, $row->work_date, $now);
        }

        $this->info("Settled {$dates->count()} work dates.");

        return self::SUCCESS;
    }
}
