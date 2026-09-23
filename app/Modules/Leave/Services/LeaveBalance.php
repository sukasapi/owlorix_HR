<?php

namespace App\Modules\Leave\Services;

use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveQuota;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Shared\Settings\Settings;

/**
 * Annual leave left for a year (docs/14 4.2): the quota (a leave_quotas row, else the setting
 * `leave.annual_quota_days`) minus the days of approved and pending requests of types that count against it.
 * A request belongs to the year of its start date; requests never cross a year.
 */
class LeaveBalance
{
    public function __construct(private readonly Settings $settings) {}

    /** @return array{year: int, quota: int, custom: bool, used: int, pending: int, remaining: int} */
    public function for(int $userId, int $year): array
    {
        return $this->forMany([$userId], $year)[$userId];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array{year: int, quota: int, custom: bool, used: int, pending: int, remaining: int}>
     */
    public function forMany(array $userIds, int $year): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        if ($ids === []) {
            return [];
        }

        $default = $this->settings->int('leave.annual_quota_days');
        $quotas = LeaveQuota::query()->whereIn('user_id', $ids)->where('year', $year)->pluck('days', 'user_id');

        $sums = LeaveRequest::query()
            ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->where('leave_types.counts_against_quota', true)
            ->whereIn('leave_requests.user_id', $ids)
            ->whereIn('leave_requests.status', LeaveStatus::holding())
            ->whereBetween('leave_requests.start_date', ["{$year}-01-01", "{$year}-12-31"])
            ->groupBy('leave_requests.user_id', 'leave_requests.status')
            ->selectRaw('leave_requests.user_id as user_id, leave_requests.status as status, SUM(leave_requests.days) as total')
            ->toBase()
            ->get();

        $result = [];

        foreach ($ids as $id) {
            $own = $sums->where('user_id', $id);
            $used = (int) $own->where('status', LeaveStatus::Approved->value)->sum('total');
            $pending = (int) $own->where('status', LeaveStatus::Pending->value)->sum('total');
            $quota = $quotas->has($id) ? (int) $quotas[$id] : $default;

            $result[$id] = [
                'year' => $year,
                'quota' => $quota,
                'custom' => $quotas->has($id),
                'used' => $used,
                'pending' => $pending,
                'remaining' => $quota - $used - $pending,
            ];
        }

        return $result;
    }
}
