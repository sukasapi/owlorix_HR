<?php

namespace Database\Factories;

use App\Modules\Attendance\Enums\EndReason;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A closed shift row without events, for tests that do not go through the calculator.
 *
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        $clockIn = CarbonImmutable::parse('2026-09-14 09:00', 'Asia/Jakarta')->utc();

        return [
            'user_id' => User::factory(),
            'work_date' => '2026-09-14',
            'is_workday' => true,
            'clock_in_at' => $clockIn,
            'regular_before_minutes' => 0,
            'regular_ends_at' => $clockIn->addMinutes(480),
            'clock_out_at' => $clockIn->addMinutes(600),
            'last_seen_at' => $clockIn->addMinutes(600),
            'status' => ShiftStatus::Closed,
            'end_reason' => EndReason::Manual,
            'regular_minutes' => 480,
            'overtime_minutes' => 120,
            'flags' => [],
            'regular_limit_minutes' => 480,
        ];
    }
}
