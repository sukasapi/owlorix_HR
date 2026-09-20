<?php

namespace Database\Factories;

use App\Modules\Attendance\Models\Shift;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OvertimeRequest>
 */
class OvertimeRequestFactory extends Factory
{
    protected $model = OvertimeRequest::class;

    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'user_id' => fn (array $attributes) => Shift::query()->find($attributes['shift_id'])->user_id,
            'reason' => 'Render final shot untuk klien',
            'work_report' => 'Render shot 12 selesai',
            'started_at' => fn (array $attributes) => Shift::query()->find($attributes['shift_id'])->regular_ends_at,
            'ended_at' => fn (array $attributes) => Shift::query()->find($attributes['shift_id'])->clock_out_at,
            'minutes' => 120,
            'is_late_claim' => false,
            'status' => OvertimeStatus::Pending,
            'submitted_at' => now(),
        ];
    }
}
