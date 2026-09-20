<?php

use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\Correction;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Access\Role;
use App\Modules\Organization\Models\Team;
use App\Modules\Overtime\Actions\DecideOvertime;
use App\Modules\Overtime\Enums\Decision;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Shared\Audit\AuditLog;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\Corrections\Support\Koreksi;

// Proposal, apply, decline and direct corrections with their effect on minutes, overtime decisions, the audit log and
// the person's notifications (3.10, I12). Monday 2026-09-14, Asia/Jakarta; corrections on Tuesday morning.

beforeEach(function () {
    $this->admin = userWithRole(Role::Superadmin);
    $this->lead = userWithRole(Role::TeamLead);
    $this->member = userWithRole(Role::Employee);
    Team::factory()->create(['lead_user_id' => $this->lead->id])->members()->attach([$this->lead->id, $this->member->id]);
    $this->desk = Desk::for($this, $this->member);
});

it('recalculates regular time, overtime and the later shift of the date when a proposal is applied', function () {
    // 09.00 to 12.00, then 13.00 into overtime: the 8-hour mark is 18.00, overtime chosen at 18.05, clock-out 18.30
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('clock_out', at: '12:00');
    $first = $this->desk->shift();
    $this->desk->send('clock_in', at: '13:00');
    $this->desk->heartbeat('17:58');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '18:05');
    $this->desk->send('clock_out', ['work_report' => 'Render shot 12 selesai'], '18:30');
    $second = $this->desk->shift();

    $request = OvertimeRequest::query()->sole();
    expect($request->minutes)->toBe(30);
    app(DecideOvertime::class)($this->lead, $request, Decision::Approved);

    $this->travelTo(Desk::time('2026-09-15 09:00'));

    // The person worked until 12.20 before the break
    Koreksi::submit($this, $this->lead, $first, 'clock_out_at', '2026-09-14 12:20', 'Rapat klien selesai 12.20, lupa absen pulang')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', "Koreksi untuk {$this->member->name} dikirim ke Superadmin.");

    $correction = Correction::query()->sole();

    expect($first->refresh()->regular_minutes)->toBe(180)
        ->and(AttendanceEvent::query()->where('type', 'correction_applied')->exists())->toBeFalse();

    Koreksi::apply($this, $this->admin, $correction)->assertSessionHasNoErrors()->assertSessionHas('status');

    expect($first->refresh())
        ->clock_out_at->toEqual(Desk::time('2026-09-14 12:20'))
        ->end_reason->value->toBe('superadmin')
        ->regular_minutes->toBe(200)
        ->and($second->refresh())
        ->regular_before_minutes->toBe(200)
        ->regular_minutes->toBe(280)
        ->regular_ends_at->toEqual(Desk::time('2026-09-14 17:40'))
        ->overtime_minutes->toBe(50)
        ->is_short->toBeFalse();

    // I12: the approved overtime covered 30 minutes, so it waits for a new decision
    expect($request->refresh())->minutes->toBe(50)->status->value->toBe('pending')
        ->and(AuditLog::query()->where('action', 'overtime.reset_to_pending')->sole()->actor_id)->toBe($this->admin->id);
});

it('keeps a declined proposal out of the shift, with the note of the Superadmin', function () {
    $shift = Koreksi::workedShift($this, $this->member, '2026-09-14 09:00', '15:00');
    $this->travelTo(Desk::time('2026-09-15 09:00'));
    Koreksi::submit($this, $this->lead, $shift, 'clock_out_at', '2026-09-14 17:00');
    $correction = Correction::query()->sole();

    Koreksi::decline($this, $this->admin, $correction, '  ')->assertSessionHasErrors(['note' => 'Tulis catatan saat menolak koreksi.']);
    expect($correction->refresh()->status->value)->toBe('proposed');

    $this->travelTo(Desk::time('2026-09-15 10:00'));
    Koreksi::decline($this, $this->admin, $correction, 'Log PC menunjukkan PC mati pukul 15.00')->assertSessionHasNoErrors();

    expect($correction->refresh())
        ->status->value->toBe('declined')
        ->decision_note->toBe('Log PC menunjukkan PC mati pukul 15.00')
        ->approved_by->toBe($this->admin->id)
        ->decided_at->toEqual(Desk::time('2026-09-15 10:00'))
        ->and($shift->refresh()->regular_minutes)->toBe(360)
        ->and(AttendanceEvent::query()->where('type', 'correction_applied')->exists())->toBeFalse()
        ->and($this->member->notifications()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'correction.declined')->sole())
        ->actor_id->toBe($this->admin->id)
        ->after->toMatchArray(['status' => 'declined', 'note' => 'Log PC menunjukkan PC mati pukul 15.00']);

    // A decided proposal cannot be decided again
    Koreksi::apply($this, $this->admin, $correction)->assertSessionHasErrors(['correction' => "Koreksi ini sudah ditolak oleh {$this->admin->name}."]);
});

it('applies a direct correction with its audit row and notification', function () {
    $shift = Koreksi::workedShift($this, $this->member, '2026-09-14 10:30', '17:00');
    $this->travelTo(Desk::time('2026-09-15 09:00'));

    Koreksi::submit($this, $this->admin, $shift, 'clock_in_at', '2026-09-14 09:00', 'short')->assertSessionHasErrors('reason');

    Koreksi::submit($this, $this->admin, $shift, 'clock_in_at', '2026-09-14 09:00', 'Datang 09.00, lupa buka aplikasi')
        ->assertSessionHasNoErrors();

    $correction = Correction::query()->sole();

    expect($shift->refresh())
        ->clock_in_at->toEqual(Desk::time('2026-09-14 09:00'))
        ->regular_minutes->toBe(480)
        ->and($correction->isDirect())->toBeTrue();

    $audit = AuditLog::query()->where('action', 'correction.applied')->sole();

    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->subject_id)->toBe($correction->id)
        ->and($audit->before)->toMatchArray(['field' => 'clock_in_at', 'value' => Koreksi::iso('2026-09-14 10:30')])
        ->and($audit->before['shift'])->toMatchArray(['regular_minutes' => 390])
        ->and($audit->after)->toMatchArray([
            'value' => Koreksi::iso('2026-09-14 09:00'),
            'reason' => 'Datang 09.00, lupa buka aplikasi',
            'person_id' => $this->member->id,
            'direct' => true,
            'closed_month' => false,
        ])
        ->and($audit->after['shift'])->toMatchArray(['regular_minutes' => 480])
        ->and($this->member->notifications()->sole()->data['field'])->toBe('clock_in_at');
});

it('sends a decided overtime back to pending when a correction changes its minutes', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:02');
    $this->desk->send('clock_out', ['work_report' => 'Render shot 12 selesai'], '20:00');
    $request = OvertimeRequest::query()->sole();
    app(DecideOvertime::class)($this->lead, $request, Decision::Rejected, 'Tidak diminta klien');

    $this->travelTo(Desk::time('2026-09-15 09:00'));
    Koreksi::submit($this, $this->admin, $this->desk->shift(), 'overtime_ended_at', '2026-09-14 19:00')->assertSessionHasNoErrors();

    expect($request->refresh())->minutes->toBe(120)->status->value->toBe('pending')
        ->and($request->decisions()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'overtime.reset_to_pending')->sole())
        ->before->toMatchArray(['status' => 'rejected', 'minutes' => 180]);
});

it('refuses to apply a proposal when the shift changed since the Superadmin saw it', function () {
    $shift = Koreksi::workedShift($this, $this->member, '2026-09-14 09:00', '15:00');
    $this->travelTo(Desk::time('2026-09-15 09:00'));
    Koreksi::submit($this, $this->lead, $shift, 'clock_out_at', '2026-09-14 17:00');
    $proposal = Correction::query()->sole();

    Koreksi::submit($this, $this->admin, $shift, 'clock_out_at', '2026-09-14 16:00')->assertSessionHasNoErrors();

    Koreksi::apply($this, $this->admin, $proposal, seen: Koreksi::iso('2026-09-14 15:00'))
        ->assertSessionHasErrors(['correction' => 'Shift ini berubah: jam pulang sekarang tercatat 16.00. Periksa lagi sebelum menerapkan.']);

    expect($proposal->refresh()->status->value)->toBe('proposed');

    Koreksi::apply($this, $this->admin, $proposal, seen: Koreksi::iso('2026-09-14 16:00'))->assertSessionHasNoErrors();

    expect($proposal->refresh())->status->value->toBe('applied')->old_value->toBe(Koreksi::iso('2026-09-14 16:00'))
        ->and($shift->refresh()->clock_out_at)->toEqual(Desk::time('2026-09-14 17:00'));
});

it('refuses corrections the rules cannot take', function (string $field, string $at, string $message) {
    // 09.00 to 15.00 on Monday, 16.00 to 17.00 on Monday, a running shift on Tuesday
    $shift = Koreksi::workedShift($this, $this->member, '2026-09-14 09:00', '15:00');
    Koreksi::workedShift($this, $this->member, '2026-09-14 16:00', '17:00');
    $this->travelTo(Desk::time('2026-09-15 09:00'));

    Koreksi::submit($this, $this->admin, $shift, $field, $at)->assertSessionHasErrors(['time' => $message]);

    expect(Correction::query()->count())->toBe(0);
})->with([
    'same time' => ['clock_out_at', '2026-09-14 15:00', 'Jam pulang sudah tercatat 15.00.'],
    'in the future' => ['clock_out_at', '2026-09-15 10:00', 'Jam koreksi tidak boleh lebih dari sekarang.'],
    'past the next clock-in' => ['clock_out_at', '2026-09-14 16:30', 'Jam pulang tidak boleh melewati absen masuk berikutnya (14 Sep 16.00).'],
    'clock-out before clock-in' => ['clock_out_at', '2026-09-14 08:00', 'Jam pulang harus setelah jam masuk (09.00).'],
    'clock-in on another date' => ['clock_in_at', '2026-09-13 22:00', 'Jam masuk harus tetap di tanggal kerja shift ini. Shift di tanggal lain belum bisa dibuat lewat koreksi.'],
    'clock-in after clock-out' => ['clock_in_at', '2026-09-14 15:30', 'Jam masuk harus sebelum jam pulang (15.00).'],
    'overtime start before the 8 hours' => ['overtime_started_at', '2026-09-14 14:00', 'Lembur baru bisa mulai setelah jam reguler hari ini penuh, pukul 17.00.'],
    'overtime end without overtime' => ['overtime_ended_at', '2026-09-14 14:00', 'Shift ini tidak punya lembur. Koreksi mulai lembur dulu.'],
]);

it('refuses corrections of a running shift and of overtime on a non-workday', function () {
    $this->desk->send('clock_in', at: '2026-09-15 08:00');
    $running = $this->desk->shift();
    $this->travelTo(Desk::time('2026-09-15 09:00'));

    Koreksi::submit($this, $this->admin, $running, 'clock_in_at', '2026-09-15 07:30')
        ->assertSessionHasErrors(['time' => 'Shift ini masih berjalan. Koreksi bisa dibuat setelah shift selesai.']);

    // Saturday
    $saturday = Koreksi::workedShift($this, $this->lead, '2026-09-19 10:00', '14:00');
    $this->travelTo(Desk::time('2026-09-21 09:00'));

    Koreksi::submit($this, $this->admin, $saturday, 'overtime_started_at', '2026-09-19 11:00')
        ->assertSessionHasErrors(['time' => 'Tanggal ini bukan hari kerja, jadi seluruh shift sudah lembur sejak jam masuk. Koreksi jam masuk kalau perlu.']);
});

it('allows a correction in a closed month and records that it was', function () {
    $shift = Koreksi::workedShift($this, $this->member, '2026-08-31 09:00', '15:00');
    $this->travelTo(Desk::time('2026-09-02 09:00'));

    Koreksi::preview($this, $this->admin, $shift, 'clock_out_at', '2026-08-31 17:00')
        ->assertOk()
        ->assertJsonPath('closed_month', true);

    Koreksi::submit($this, $this->admin, $shift, 'clock_out_at', '2026-08-31 17:00')->assertSessionHasNoErrors();

    expect($shift->refresh()->regular_minutes)->toBe(480)
        ->and(AuditLog::query()->where('action', 'correction.applied')->sole()->after['closed_month'])->toBeTrue();
});

it('keeps the corrected shift when an offline clock-in arrives later in its time', function () {
    $shift = Koreksi::workedShift($this, $this->member, '2026-09-14 09:00', '12:00');
    $this->travelTo(Desk::time('2026-09-15 09:00'));
    Koreksi::submit($this, $this->admin, $shift, 'clock_out_at', '2026-09-14 13:00')->assertSessionHasNoErrors();

    // A second PC uploads a clock-in and clock-out of the afternoon that it kept offline
    $laptop = Desk::for($this, $this->member, 'PC-ANIM-11');
    $laptop->sync([
        $laptop->event('clock_in', at: '2026-09-14 14:00', overrides: ['offline' => true]),
        $laptop->event('clock_out', at: '2026-09-14 16:00', overrides: ['offline' => true]),
    ])->assertOk();

    expect(AttendanceEvent::query()->where('type', 'correction_applied')->sole()->shift_id)->toBe($shift->id)
        ->and($shift->refresh())->clock_out_at->toEqual(Desk::time('2026-09-14 13:00'))->regular_minutes->toBe(240)
        ->and(Shift::query()->where('user_id', $this->member->id)->count())->toBe(2);
});
