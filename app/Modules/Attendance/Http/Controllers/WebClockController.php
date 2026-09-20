<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Calculation\ShiftRules;
use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Services\WebClock;
use App\Modules\Attendance\Services\WebDevice;
use App\Modules\Attendance\Support\Time;
use App\Modules\Calendar\Services\WorkdayResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Clock in, undo, move, resume, clock out, and heartbeat from the browser (3.11). Every action answers with a
 * redirect to Hari ini, which the page reloads partially; refusals come back as an error code in `errors.clock`.
 */
class WebClockController extends Controller
{
    /** Heartbeats closer together than this (a second tab of Hari ini) are not written again */
    private const HEARTBEAT_MIN_SECONDS = 30;

    public function __construct(
        private readonly WebClock $clock,
        private readonly WebDevice $devices,
    ) {}

    /** 3.1.2, 3.1.5: an explicit "Absen masuk"; a non-workday asks for the overtime reason first (3.4.8). */
    public function clockIn(Request $request, WorkdayResolver $calendar): RedirectResponse
    {
        $user = $request->user();
        $now = CarbonImmutable::now();
        $isWorkday = $calendar->isWorkday($user, Time::workDate($now));
        $data = $request->validate([
            'reason' => [$isWorkday ? 'nullable' : 'required', 'string', 'min:10', 'max:2000'],
        ]);
        $device = $this->devices->resolve($request, $user, $now);

        $this->clock->exclusively($user, function () use ($user, $device, $now, $isWorkday, $data) {
            if (($open = $this->clock->openShift($user, $now)) !== null) {
                throw WebClock::refuse($open->result->deviceId === $device->id ? 'already_clocked_in' : 'moved_elsewhere');
            }

            $this->clock->record($user, $device, EventType::ClockIn, $isWorkday ? [] : ['reason' => trim($data['reason'])], $now);
        });

        return to_route('my-day');
    }

    /** 3.1.2: undo within 2 minutes. */
    public function cancel(Request $request): RedirectResponse
    {
        $user = $request->user();
        $now = CarbonImmutable::now();
        $device = $this->devices->resolve($request, $user, $now);

        $this->clock->exclusively($user, function () use ($user, $device, $now) {
            $open = $this->clock->openShift($user, $now);

            if ($open === null || $open->result->deviceId !== $device->id) {
                throw WebClock::refuse('no_open_shift');
            }

            if ($now->greaterThan($open->result->clockInAt->addSeconds(ShiftRules::CANCEL_WINDOW_SECONDS))) {
                throw WebClock::refuse('undo_expired');
            }

            $this->clock->record($user, $device, EventType::ClockInCancelled, [], $now);
        });

        return to_route('my-day');
    }

    /** 3.1.3: "Kamu sedang absen masuk di <perangkat>. Pindahkan ke sini?" Yes moves the shift to this browser. */
    public function move(Request $request): RedirectResponse
    {
        $user = $request->user();
        $now = CarbonImmutable::now();
        $device = $this->devices->resolve($request, $user, $now);

        $this->clock->exclusively($user, function () use ($user, $device, $now) {
            $open = $this->clock->openShift($user, $now);

            if ($open === null) {
                throw WebClock::refuse('no_open_shift');
            }

            if ($open->result->deviceId !== $device->id) {
                $this->clock->record($user, $device, EventType::ShiftMoved, [], $now);
            }
        });

        return to_route('my-day');
    }

    /** 3.7.3: "Lanjutkan shift" after this browser stopped sending heartbeats. */
    public function resume(Request $request): RedirectResponse
    {
        $user = $request->user();
        $now = CarbonImmutable::now();
        $device = $this->devices->resolve($request, $user, $now);

        $this->clock->exclusively($user, fn () => $this->clock->shiftOnThisBrowser($user, $device, $now, 'resume_expired'));

        return to_route('my-day');
    }

    /** Clock out, also the "Absen pulang" answer to the 8-hour prompt (3.3.3). From overtime a work report may come along (3.4.2). */
    public function clockOut(Request $request): RedirectResponse
    {
        $user = $request->user();
        $now = CarbonImmutable::now();
        $data = $request->validate(['work_report' => ['nullable', 'string', 'max:5000']]);
        $device = $this->devices->resolve($request, $user, $now);
        $report = trim((string) ($data['work_report'] ?? ''));

        $this->clock->exclusively($user, function () use ($user, $device, $now, $report) {
            $this->clock->shiftOnThisBrowser($user, $device, $now);
            $this->clock->record($user, $device, EventType::ClockOut, $report !== '' ? ['work_report' => $report] : [], $now);
        });

        return to_route('my-day');
    }

    /**
     * Heartbeat every 60 seconds while Hari ini is open with this browser's shift. A shift already interrupted
     * because heartbeats stopped is not continued by a heartbeat: the person continues it with "Lanjutkan shift"
     * or any other action, so the gap is recorded (3.7.3, 3.11). Anything else is ignored without an error, so a
     * background tab never shows one.
     */
    public function heartbeat(Request $request): RedirectResponse
    {
        $user = $request->user();
        $now = CarbonImmutable::now();
        $device = $this->devices->find($request);

        if ($device !== null) {
            $this->clock->exclusively($user, function () use ($user, $device, $now) {
                $open = $this->clock->openShift($user, $now);

                if ($open !== null
                    && $open->result->deviceId === $device->id
                    && $open->result->status !== ShiftStatus::Interrupted
                    && $open->result->lastSeenAt->addSeconds(self::HEARTBEAT_MIN_SECONDS)->lessThanOrEqualTo($now)) {
                    $this->clock->record($user, $device, EventType::Heartbeat, [], $now);
                }
            });
        }

        return to_route('my-day');
    }
}
