<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\LateClaim;
use App\Modules\Attendance\Services\LateClaimRefused;
use App\Modules\Attendance\Services\ShiftStateResolver;
use App\Modules\Attendance\Services\WebClock;
use App\Modules\Attendance\Services\WebDevice;
use App\Modules\Attendance\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Overtime answers from the browser: keep working, "Masih lembur", work report, late claim (3.3, 3.4, 3.5, 3.11). */
class WebClockOvertimeController extends Controller
{
    public function __construct(
        private readonly WebClock $clock,
        private readonly WebDevice $devices,
    ) {}

    /**
     * 3.3.4: "Lanjut lembur" with a reason of at least 10 characters; overtime starts at the 8-hour mark. The server
     * knows the mark exactly for a browser, so the answer is only taken while the 8-hour prompt is open.
     */
    public function keepWorking(Request $request): RedirectResponse
    {
        $user = $request->user();
        $now = CarbonImmutable::now();
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $device = $this->devices->resolve($request, $user, $now);

        $this->clock->exclusively($user, function () use ($user, $device, $now, $data) {
            if ($this->clock->shiftOnThisBrowser($user, $device, $now)->result->status !== ShiftStatus::Prompted) {
                throw WebClock::refuse('not_prompted');
            }

            $this->clock->record($user, $device, EventType::OvertimeStart, ['reason' => trim($data['reason'])], $now);
        });

        return to_route('my-day');
    }

    /** 3.5.2, 3.11: "Masih lembur" keeps overtime running and starts the next 60 minutes. */
    public function confirmPresence(Request $request): RedirectResponse
    {
        $user = $request->user();
        $now = CarbonImmutable::now();
        $device = $this->devices->resolve($request, $user, $now);

        $this->clock->exclusively($user, function () use ($user, $device, $now) {
            if ($this->clock->shiftOnThisBrowser($user, $device, $now)->result->status !== ShiftStatus::Overtime) {
                throw WebClock::refuse('not_overtime');
            }

            $this->clock->record($user, $device, EventType::PresenceConfirmed, [], $now);
        });

        return to_route('my-day');
    }

    /** 3.4.2: the work report of any overtime still due, from whichever device the shift ran on. */
    public function report(Request $request, ShiftStateResolver $resolver): RedirectResponse
    {
        $user = $request->user();
        $now = CarbonImmutable::now();
        $data = $request->validate([
            'shift_id' => ['required', 'integer'],
            'work_report' => ['required', 'string', 'max:5000'],
        ]);

        $device = $this->devices->resolve($request, $user, $now);

        $this->clock->exclusively($user, function () use ($user, $device, $now, $data, $resolver) {
            // Only the person's own shifts are looked up, so another person's shift id reads as "not due"
            $shift = Shift::query()->where('user_id', $user->id)->find($data['shift_id']);
            $resolved = $shift !== null ? $resolver->shift($shift, $now) : null;

            if ($resolved === null || ! $resolved->result->reportDue()) {
                throw WebClock::refuse('report_not_due', 'work_report');
            }

            $this->clock->record($user, $device, EventType::OvertimeReport, [
                'shift_id' => $shift->id,
                'work_report' => trim($data['work_report']),
            ], $now);
        });

        return to_route('my-day');
    }

    /** 3.3.6, 3.5.5: late claim, bounded by the last activity recorded for the shift. */
    public function claim(Request $request, LateClaim $claims): RedirectResponse
    {
        $user = $request->user();
        $now = CarbonImmutable::now();
        $data = $request->validate([
            'shift_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'work_report' => ['required', 'string', 'max:5000'],
            // A time from the form is studio wall clock (Asia/Jakarta); an ISO time with a zone is taken as given
            'ended_at' => ['required', 'date'],
        ]);

        $endedAt = CarbonImmutable::parse($data['ended_at'], Time::zone())->utc();

        try {
            $claims->file($user, $this->devices->resolve($request, $user, $now), (int) $data['shift_id'], $data['reason'], $data['work_report'], $endedAt, $now);
        } catch (LateClaimRefused $refused) {
            throw WebClock::refuse($refused->reason, 'ended_at');
        }

        return to_route('my-day');
    }
}
