<?php

namespace App\Modules\Overtime\Listeners;

use App\Modules\Attendance\Events\ShiftRecalculated;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Shared\Audit\Auditor;

/**
 * Creates or updates the overtime request of a recalculated shift (3.4.3). A new request starts as pending and is
 * routed to Management; later recalculations update times, minutes, reason and report.
 *
 * A decision covers the minutes it was made on. When the minutes change afterwards (late claim, late events, a
 * calendar change, a correction), the request goes back to pending and the reset is audited, so an approval never
 * silently covers different minutes (3.4.5). A request whose overtime disappeared keeps its decision history with
 * zero minutes; one never decided is removed.
 *
 * Overtime that ended with 0 minutes has nothing to decide, so it is treated like overtime that disappeared: no request
 * is created and an undecided one is removed. A decided one keeps its history. Running overtime keeps its request at
 * 0 minutes, because its minutes still grow (I16).
 */
class SyncOvertimeRequest
{
    public function __construct(private readonly Auditor $auditor) {}

    public function handle(ShiftRecalculated $event): void
    {
        $shift = $event->shift;
        $overtime = $event->result->overtime;
        $request = OvertimeRequest::query()->where('shift_id', $shift->id)->first();

        if ($overtime === null || ($overtime->endedAt !== null && $overtime->minutes === 0)) {
            if ($request === null || ! $request->decisions()->exists()) {
                $request?->delete();

                return;
            }

            if ($overtime === null) {
                $this->update($request, ['minutes' => 0]);

                return;
            }
        }

        $attributes = [
            'user_id' => $shift->user_id,
            'reason' => $overtime->reason ?? '',
            'work_report' => $overtime->workReport,
            'started_at' => $overtime->startedAt,
            'ended_at' => $overtime->endedAt,
            'minutes' => $overtime->minutes,
            'is_late_claim' => $overtime->isLateClaim,
            'submitted_at' => $overtime->reportSubmittedAt,
        ];

        if ($request === null) {
            OvertimeRequest::query()->create($attributes + ['shift_id' => $shift->id, 'status' => OvertimeStatus::Pending]);

            return;
        }

        $this->update($request, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    private function update(OvertimeRequest $request, array $attributes): void
    {
        $before = ['status' => $request->status->value, 'minutes' => $request->minutes];
        $reset = $request->status !== OvertimeStatus::Pending && $attributes['minutes'] !== $request->minutes;

        if ($reset) {
            $attributes['status'] = OvertimeStatus::Pending;
        }

        $request->update($attributes);

        if ($reset) {
            $this->auditor->record('overtime.reset_to_pending', $request, $before, [
                'status' => OvertimeStatus::Pending->value,
                'minutes' => $request->minutes,
                'reason' => 'minutes_changed',
            ]);
        }
    }
}
