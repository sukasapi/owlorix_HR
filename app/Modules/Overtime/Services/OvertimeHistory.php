<?php

namespace App\Modules\Overtime\Services;

use App\Modules\Attendance\Support\Time;
use App\Modules\Overtime\Models\OvertimeDecision;
use App\Modules\Overtime\Models\OvertimeRequest;

/** Overtime requests with their decision history, for the person's own pages (Riwayat, Lembur). */
class OvertimeHistory
{
    /**
     * @param  list<int>  $shiftIds
     * @return array<int, OvertimeRequest> keyed by shift id, decisions and deciders loaded
     */
    public function forShifts(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        return OvertimeRequest::query()
            ->whereIn('shift_id', $shiftIds)
            ->with('decisions.decider:id,name')
            ->get()
            ->keyBy('shift_id')
            ->all();
    }

    /**
     * Oldest first; the last entry is the current decision. A pending request with entries was decided before and
     * went back to pending because its minutes changed (SyncOvertimeRequest).
     *
     * @return list<array{decision: string, note: string|null, decided_at: string|null, decided_by: string|null}>
     */
    public function decisions(OvertimeRequest $request): array
    {
        return $request->decisions->map(fn (OvertimeDecision $d) => [
            'decision' => $d->decision->value,
            'note' => $d->note,
            'decided_at' => Time::iso($d->decided_at),
            'decided_by' => $d->decider?->name,
        ])->values()->all();
    }
}
