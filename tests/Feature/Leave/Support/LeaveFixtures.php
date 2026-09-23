<?php

namespace Tests\Feature\Leave\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveDays;

/** Leave rows for tests, built without the HTTP flow when the flow itself is not what is tested. */
final class LeaveFixtures
{
    public static function type(string $code): LeaveType
    {
        return LeaveType::query()->where('code', $code)->sole();
    }

    public static function request(User $person, string $start, string $end, LeaveStatus $status = LeaveStatus::Pending, string $type = 'annual', array $overrides = []): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'user_id' => $person->id,
            'leave_type_id' => self::type($type)->id,
            'start_date' => $start,
            'end_date' => $end,
            'days' => count(app(LeaveDays::class)->workdays($person, $start, $end)),
            'status' => $status,
            ...$overrides,
        ]);
    }
}
