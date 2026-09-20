<?php

use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\Correction;
use App\Modules\Identity\Access\Role;
use App\Modules\Organization\Models\Team;
use App\Modules\Shared\Audit\AuditLog;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\Corrections\Support\Koreksi;

// Corrections (docs/02-attendance-rules.md 3.10). Monday 2026-09-14, Asia/Jakarta; corrections are made the next day.
// The flow in detail is in tests/Feature/Corrections.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->admin = userWithRole(Role::Superadmin);
    $this->lead = userWithRole(Role::TeamLead);
    Team::factory()->create(['lead_user_id' => $this->lead->id])->members()->attach([$this->lead->id, $this->person->id]);
});

test('3.10.1 Superadmin edits clock-in, clock-out and overtime times with a required reason', function () {
    $desk = Desk::for($this, $this->person);
    $desk->send('clock_in', at: '2026-09-14 09:30');
    $desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:40');
    $desk->send('clock_out', ['work_report' => 'Render shot 12 selesai'], '21:00');
    $shift = $desk->shift();

    $this->travelTo(Desk::time('2026-09-15 09:00'));

    Koreksi::submit($this, $this->admin, $shift, 'clock_out_at', '2026-09-14 20:00', reason: '')->assertSessionHasErrors('reason');
    expect(Correction::query()->count())->toBe(0);

    Koreksi::submit($this, $this->admin, $shift, 'clock_in_at', '2026-09-14 09:00')->assertSessionHasNoErrors();
    Koreksi::submit($this, $this->admin, $shift, 'overtime_started_at', '2026-09-14 17:30')->assertSessionHasNoErrors();
    Koreksi::submit($this, $this->admin, $shift, 'overtime_ended_at', '2026-09-14 20:30')->assertSessionHasNoErrors();
    Koreksi::submit($this, $this->admin, $shift, 'clock_out_at', '2026-09-14 20:45')->assertSessionHasNoErrors();

    expect($shift->refresh())
        ->clock_in_at->toEqual(Desk::time('2026-09-14 09:00'))
        ->clock_out_at->toEqual(Desk::time('2026-09-14 20:45'))
        ->regular_minutes->toBe(480)
        // 17.30 to 20.30: the corrected overtime end stays when the clock-out moves after it
        ->overtime_minutes->toBe(180)
        ->and(Correction::query()->where('status', 'applied')->count())->toBe(4);
});

test('3.10.2 Management proposes corrections for their people and Superadmin applies them', function () {
    $shift = Koreksi::workedShift($this, $this->person, '2026-09-14 09:00', '15:00');
    $this->travelTo(Desk::time('2026-09-15 09:00'));

    Koreksi::submit($this, $this->lead, $shift, 'clock_out_at', '2026-09-14 17:00')->assertSessionHasNoErrors();

    $correction = Correction::query()->sole();

    expect($correction->status->value)->toBe('proposed')
        ->and($shift->refresh()->regular_minutes)->toBe(360);

    Koreksi::apply($this, $this->lead, $correction)->assertForbidden();
    Koreksi::apply($this, $this->admin, $correction)->assertSessionHasNoErrors();

    expect($correction->refresh()->status->value)->toBe('applied')
        ->and($shift->refresh()->regular_minutes)->toBe(480)
        ->and($shift->is_short)->toBeFalse();
});

test('3.10.3 nobody applies a correction to their own records and the person is notified', function () {
    $own = Koreksi::workedShift($this, $this->admin, '2026-09-14 09:00', '15:00');
    $shift = Koreksi::workedShift($this, $this->person, '2026-09-14 09:00', '15:00');
    $leadShift = Koreksi::workedShift($this, $this->lead, '2026-09-14 09:00', '15:00');
    $this->travelTo(Desk::time('2026-09-15 09:00'));

    Koreksi::submit($this, $this->admin, $own, 'clock_out_at', '2026-09-14 17:00')->assertSessionHasErrors('correction');
    Koreksi::submit($this, $this->lead, $leadShift, 'clock_out_at', '2026-09-14 17:00')->assertSessionHasErrors('correction');

    expect(Correction::query()->count())->toBe(0)
        ->and($own->refresh()->regular_minutes)->toBe(360);

    Koreksi::submit($this, $this->admin, $shift, 'clock_out_at', '2026-09-14 17:00')->assertSessionHasNoErrors();

    $notification = $this->person->notifications()->sole();

    expect($notification->type)->toBe('correction.applied')
        ->and($notification->data)->toMatchArray([
            'field' => 'clock_out_at',
            'old_value' => Koreksi::iso('2026-09-14 15:00'),
            'new_value' => Koreksi::iso('2026-09-14 17:00'),
            'reason' => Koreksi::REASON,
            'applied_by' => ['id' => $this->admin->id, 'name' => $this->admin->name],
        ]);
});

test('3.10.4 every correction stores old value, new value, who, when and why', function () {
    $shift = Koreksi::workedShift($this, $this->person, '2026-09-14 09:00', '15:00');
    $this->travelTo(Desk::time('2026-09-15 09:00'));
    Koreksi::submit($this, $this->lead, $shift, 'clock_out_at', '2026-09-14 17:00');

    $this->travelTo(Desk::time('2026-09-15 10:30'));
    Koreksi::apply($this, $this->admin, Correction::query()->sole());

    expect(Correction::query()->sole())
        ->shift_id->toBe($shift->id)
        ->field->value->toBe('clock_out_at')
        ->old_value->toBe(Koreksi::iso('2026-09-14 15:00'))
        ->new_value->toBe(Koreksi::iso('2026-09-14 17:00'))
        ->reason->toBe(Koreksi::REASON)
        ->proposed_by->toBe($this->lead->id)
        ->approved_by->toBe($this->admin->id)
        ->created_at->toEqual(Desk::time('2026-09-15 09:00'))
        ->decided_at->toEqual(Desk::time('2026-09-15 10:30'));

    // Events are never edited: the device clock-out stays, the correction is a server event of its own
    expect(AttendanceEvent::query()->where('type', 'clock_out')->sole()->occurred_at)->toEqual(Desk::time('2026-09-14 15:00'))
        ->and(AttendanceEvent::query()->where('type', 'correction_applied')->sole())
        ->boot_id->toBe('server')
        ->shift_id->toBe($shift->id)
        ->payload->toMatchArray(['field' => 'clock_out_at', 'value' => Koreksi::iso('2026-09-14 17:00')])
        ->and(AuditLog::query()->orderBy('id')->pluck('action')->all())->toBe(['correction.proposed', 'correction.applied'])
        ->and(AuditLog::query()->where('action', 'correction.applied')->sole()->actor_id)->toBe($this->admin->id);
});
