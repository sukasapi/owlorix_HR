<?php

namespace App\Modules\Attendance\Enums;

/** The shift times a correction can change (3.10.1). Values are stored in `corrections.field` and event payloads. */
enum CorrectionField: string
{
    case ClockIn = 'clock_in_at';
    case ClockOut = 'clock_out_at';
    case OvertimeStart = 'overtime_started_at';
    case OvertimeEnd = 'overtime_ended_at';

    /** Words for server messages (Indonesian, as the other attendance messages). */
    public function label(): string
    {
        return match ($this) {
            self::ClockIn => 'jam masuk',
            self::ClockOut => 'jam pulang',
            self::OvertimeStart => 'mulai lembur',
            self::OvertimeEnd => 'selesai lembur',
        };
    }
}
