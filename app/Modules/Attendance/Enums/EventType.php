<?php

namespace App\Modules\Attendance\Enums;

/**
 * Attendance event types (docs/03-architecture.md 4.1).
 * `clock_in_cancelled` (3.1.2) and `presence_confirmed` (3.5.2) come from the rules. `overtime_claim` (a late claim,
 * 3.3.6) and `correction_applied` (a Superadmin correction, 3.10) are written by the server and never accepted from a
 * device.
 */
enum EventType: string
{
    case ClockIn = 'clock_in';
    case ClockInCancelled = 'clock_in_cancelled';
    case ClockOut = 'clock_out';
    case RegularTimeReached = 'regular_time_reached';
    case OvertimeStart = 'overtime_start';
    case OvertimeReason = 'overtime_reason';
    case OvertimeReport = 'overtime_report';
    case AutoClockOut = 'auto_clock_out';
    case IdleStart = 'idle_start';
    case IdleEnd = 'idle_end';
    case IdleTag = 'idle_tag';
    case Heartbeat = 'heartbeat';
    case PcShutdown = 'pc_shutdown';
    case PcSleep = 'pc_sleep';
    case PcWake = 'pc_wake';
    case ShiftMoved = 'shift_moved';
    case ShiftResumed = 'shift_resumed';
    case PresenceConfirmed = 'presence_confirmed';
    case OvertimeClaim = 'overtime_claim';
    case CorrectionApplied = 'correction_applied';

    /** @return list<string> */
    public static function fromDevice(): array
    {
        return array_values(array_map(
            fn (self $type) => $type->value,
            array_filter(self::cases(), fn (self $type) => ! $type->isServerWritten()),
        ));
    }

    public function isServerWritten(): bool
    {
        return $this === self::OvertimeClaim || $this === self::CorrectionApplied;
    }

    /** The person is back at a PC: ends an interruption that is still inside the resume window (3.7.3). */
    public function showsPresence(): bool
    {
        return in_array($this, [
            self::ShiftResumed,
            self::ShiftMoved,
            self::ClockOut,
            self::OvertimeStart,
            self::PresenceConfirmed,
            self::IdleEnd,
        ], true);
    }
}
