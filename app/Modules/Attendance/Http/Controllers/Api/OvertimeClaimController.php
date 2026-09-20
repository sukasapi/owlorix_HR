<?php

namespace App\Modules\Attendance\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Http\Middleware\AuthenticateDevice;
use App\Modules\Attendance\Http\Requests\OvertimeClaimRequest;
use App\Modules\Attendance\Services\LateClaim;
use App\Modules\Attendance\Services\LateClaimRefused;
use App\Modules\Attendance\Support\Time;
use App\Modules\Overtime\Services\OvertimeLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/** Late overtime claim from the desktop app. `{shift}` is the shift id: an auto-closed shift has no overtime request yet. */
class OvertimeClaimController extends Controller
{
    public function __invoke(OvertimeClaimRequest $request, int $shift, LateClaim $claims, OvertimeLookup $overtime): JsonResponse
    {
        try {
            $claimed = $claims->file(
                $request->user(),
                AuthenticateDevice::device($request),
                $shift,
                $request->string('reason')->toString(),
                $request->string('work_report')->toString(),
                Time::parse($request->string('ended_at')->toString()),
            );
        } catch (LateClaimRefused $refused) {
            if ($refused->reason === 'before_auto_end') {
                throw ValidationException::withMessages(['ended_at' => $refused->getMessage()]);
            }

            return response()->json(array_filter([
                'message' => $refused->getMessage(),
                'code' => $refused->reason,
                'latest_end_at' => Time::iso($refused->latestEndAt),
            ], fn ($value) => $value !== null), $refused->status);
        }

        $overtimeRequest = $overtime->forShifts([$shift])[$shift] ?? null;

        return response()->json([
            'shift' => $claimed?->toArray($overtimeRequest?->status->value),
            'overtime_request' => $overtimeRequest === null ? null : [
                'id' => $overtimeRequest->id,
                'status' => $overtimeRequest->status->value,
                'is_late_claim' => $overtimeRequest->is_late_claim,
                'minutes' => $overtimeRequest->minutes,
            ],
        ], 201);
    }
}
