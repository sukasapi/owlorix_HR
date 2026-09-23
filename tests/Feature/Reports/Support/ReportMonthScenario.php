<?php

namespace Tests\Feature\Reports\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Overtime\Actions\DecideOvertime;
use App\Modules\Overtime\Enums\Decision;
use App\Modules\Overtime\Models\OvertimeRequest;
use Tests\Feature\Attendance\Support\Desk;
use Tests\TestCase;

/**
 * One person's September 2026 played through the desktop API (Asia/Jakarta wall clock). Monday 2026-09-14 to
 * Wednesday 2026-09-23; Saturday 2026-09-19 is not a workday.
 */
final class ReportMonthScenario
{
    /** What the recap must add up to for September 2026. */
    public const TOTALS = [
        'days_worked' => 9,
        'regular_minutes' => 480 + 480 + 480 + 480 + 480 + 0 + 360 + 480 + 330,
        'overtime_approved_minutes' => 120,
        'overtime_pending_minutes' => 60 + 180,
        'overtime_rejected_minutes' => 30,
        'idle_minutes' => 30,
        'short_days' => 2,
        'non_workday_shifts' => 1,
        'review_shifts' => 0,
        'late_claims' => 0,
        'pending_shifts' => 2,
        'running_shifts' => 0,
        'leave_days' => 0,
    ];

    public const SHIFTS = 10;

    public static function play(TestCase $test, User $person, User $approver): Desk
    {
        $desk = Desk::for($test, $person);
        $decide = app(DecideOvertime::class);

        // Mon 14: a normal 8-hour day
        $desk->send('clock_in', at: '2026-09-14 09:00');
        $desk->send('clock_out', at: '17:00');

        // Tue 15: two shifts adding up to 8 hours
        $desk->send('clock_in', at: '2026-09-15 09:00');
        $desk->send('clock_out', at: '13:00');
        $desk->send('clock_in', at: '14:00');
        $desk->send('clock_out', at: '18:00');

        // Wed 16: 2 hours of overtime, approved
        $desk->send('clock_in', at: '2026-09-16 09:00');
        $desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:03');
        $desk->send('clock_out', ['work_report' => 'Render shot 12 selesai'], '19:00');
        $decide($approver, self::request($desk), Decision::Approved);

        // Thu 17: 1 hour of overtime, still pending
        $desk->send('clock_in', at: '2026-09-17 09:00');
        $desk->send('overtime_start', ['reason' => 'Revisi animasi shot 14'], '17:02');
        $desk->send('clock_out', ['work_report' => 'Revisi shot 14 selesai'], '18:00');

        // Fri 18: 30 minutes of overtime, rejected
        $desk->send('clock_in', at: '2026-09-18 09:00');
        $desk->send('overtime_start', ['reason' => 'Rapikan file proyek'], '17:01');
        $desk->send('clock_out', ['work_report' => 'File proyek dirapikan'], '17:30');
        $decide($approver, self::request($desk), Decision::Rejected, 'Bisa dikerjakan besok pagi');

        // Sat 19: not a workday, the whole shift is overtime (pending)
        $desk->send('clock_in', ['reason' => 'Revisi klien untuk Senin'], '2026-09-19 10:00');
        $desk->send('clock_out', ['work_report' => 'Revisi klien selesai'], '13:00');

        // Mon 21: a short day
        $desk->send('clock_in', at: '2026-09-21 09:00');
        $desk->send('clock_out', at: '15:00');

        // Tue 22: 30 minutes of PC quiet time, not deducted
        $desk->send('clock_in', at: '2026-09-22 09:00');
        $desk->at('10:12');
        $desk->sync([$desk->event('idle_start', at: '10:00')])->assertOk();
        $desk->send('idle_end', at: '10:30');
        $desk->send('clock_out', at: '17:00');

        // Wed 23: an evening shift crossing midnight belongs to the 23rd, and ends short
        $desk->send('clock_in', at: '2026-09-23 20:00');
        $desk->send('clock_out', at: '2026-09-24 01:30');

        return $desk;
    }

    private static function request(Desk $desk): OvertimeRequest
    {
        return OvertimeRequest::query()->where('shift_id', $desk->shift()->id)->sole();
    }
}
