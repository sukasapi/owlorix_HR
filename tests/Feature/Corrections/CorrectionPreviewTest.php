<?php

use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\Correction;
use App\Modules\Identity\Access\Role;
use App\Modules\Organization\Models\Team;
use App\Modules\Overtime\Actions\DecideOvertime;
use App\Modules\Overtime\Enums\Decision;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\Corrections\Support\Koreksi;

// The preview shows the minutes a correction gives before it is sent, without saving anything (3.10).

beforeEach(function () {
    $this->admin = userWithRole(Role::Superadmin);
    $this->lead = userWithRole(Role::TeamLead);
    $this->member = userWithRole(Role::Employee);
    Team::factory()->create(['lead_user_id' => $this->lead->id])->members()->attach([$this->lead->id, $this->member->id]);
    $this->desk = Desk::for($this, $this->member);
});

it('shows the minutes before and after for the shift and its date, and saves nothing', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:02');
    $this->desk->send('clock_out', ['work_report' => 'Render shot 12 selesai'], '19:00');
    $shift = $this->desk->shift();
    app(DecideOvertime::class)($this->lead, OvertimeRequest::query()->sole(), Decision::Approved);

    $this->travelTo(Desk::time('2026-09-15 09:00'));

    $counts = fn () => [
        Correction::query()->count(),
        AttendanceEvent::query()->count(),
        AuditLog::query()->count(),
        DB::table('notifications')->count(),
        $shift->refresh()->only(['clock_in_at', 'clock_out_at', 'regular_minutes', 'overtime_minutes', 'updated_at']),
        OvertimeRequest::query()->sole()->only(['status', 'minutes']),
    ];
    $before = $counts();

    Koreksi::preview($this, $this->lead, $shift, 'overtime_ended_at', '2026-09-14 18:30')
        ->assertOk()
        ->assertJson([
            'shift_id' => $shift->id,
            'work_date' => '2026-09-14',
            'field' => 'overtime_ended_at',
            'old_value' => Koreksi::iso('2026-09-14 19:00'),
            'new_value' => Koreksi::iso('2026-09-14 18:30'),
            'closed_month' => false,
            'overtime_reset' => true,
            'date' => [
                'before' => ['regular_minutes' => 480, 'overtime_minutes' => 120],
                'after' => ['regular_minutes' => 480, 'overtime_minutes' => 90],
            ],
            'shifts' => [[
                'id' => $shift->id,
                'is_target' => true,
                'before' => ['overtime_ended_at' => Koreksi::iso('2026-09-14 19:00'), 'overtime_minutes' => 120],
                'after' => ['overtime_ended_at' => Koreksi::iso('2026-09-14 18:30'), 'overtime_minutes' => 90, 'clock_out_at' => Koreksi::iso('2026-09-14 19:00')],
            ]],
        ]);

    expect($counts())->toEqual($before);
});

it('returns the refusal of a correction as a form error', function () {
    $shift = Koreksi::workedShift($this, $this->member, '2026-09-14 09:00', '15:00');
    $this->travelTo(Desk::time('2026-09-15 09:00'));

    Koreksi::preview($this, $this->admin, $shift, 'clock_out_at', '2026-09-14 08:00')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['time' => 'Jam pulang harus setelah jam masuk (09.00).']);

    $this->actingAs($this->admin)->postJson(route('corrections.preview'), ['shift_id' => $shift->id, 'field' => 'lunch', 'date' => '2026-09-14', 'time' => '9.30'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['field', 'time']);
});

it('never accepts a correction event from the desktop app', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');

    $this->desk->sync([$this->desk->event('correction_applied', ['field' => 'clock_in_at', 'value' => '2026-09-14T00:00:00.000Z'])])
        ->assertOk()
        ->assertJsonPath('rejected.0.code', 'invalid')
        ->assertJsonPath('accepted', []);

    expect(AttendanceEvent::query()->where('type', 'correction_applied')->exists())->toBeFalse()
        ->and($this->desk->shift()->clock_in_at)->toEqual(Desk::time('2026-09-14 09:00'));
});
