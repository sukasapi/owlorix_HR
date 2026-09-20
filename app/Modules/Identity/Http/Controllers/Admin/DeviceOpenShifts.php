<?php

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftStateResolver;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;

/**
 * Shifts still running on given devices, read through ShiftStateResolver so a shift the time rules already ended
 * does not count. Only people with an unclosed shift that ever sent an event from one of the devices are resolved.
 */
class DeviceOpenShifts
{
    public function __construct(private readonly ShiftStateResolver $resolver) {}

    /**
     * @param  list<string>  $deviceIds
     * @return array<string, list<array{user_id: int, name: string, clock_in_at: ?string, status: string}>> keyed by device id
     */
    public function on(array $deviceIds, CarbonImmutable $now): array
    {
        if ($deviceIds === []) {
            return [];
        }

        $userIds = AttendanceEvent::query()
            ->whereIn('device_id', $deviceIds)
            ->whereIn('shift_id', Shift::query()->whereNull('clock_out_at')->select('id'))
            ->distinct()
            ->pluck('user_id');

        $found = [];

        foreach (User::withTrashed()->whereIn('id', $userIds)->orderBy('name')->get() as $user) {
            $open = $this->resolver->openShift($user, $now);

            if ($open === null || ! in_array($open->result->deviceId, $deviceIds, true)) {
                continue;
            }

            $found[$open->result->deviceId][] = [
                'user_id' => $user->id,
                'name' => $user->name,
                'clock_in_at' => Time::iso($open->result->clockInAt),
                'status' => $open->result->status->value,
            ];
        }

        return $found;
    }
}
