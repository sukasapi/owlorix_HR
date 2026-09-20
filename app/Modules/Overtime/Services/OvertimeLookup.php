<?php

namespace App\Modules\Overtime\Services;

use App\Modules\Overtime\Models\OvertimeRequest;

/** Read access to overtime requests for other modules. */
class OvertimeLookup
{
    /**
     * @param  list<int>  $shiftIds
     * @return array<int, OvertimeRequest> keyed by shift id
     */
    public function forShifts(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        return OvertimeRequest::query()
            ->whereIn('shift_id', $shiftIds)
            ->with('latestDecision')
            ->get()
            ->keyBy('shift_id')
            ->all();
    }
}
