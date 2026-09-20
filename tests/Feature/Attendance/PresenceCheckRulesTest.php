<?php

use App\Modules\Attendance\Enums\EndReason;
use App\Modules\Attendance\Enums\OvertimeEndReason;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Identity\Access\Role;
use App\Modules\Overtime\Models\OvertimeRequest;
use Tests\Feature\Attendance\Support\Desk;

// Monday 2026-09-14, Asia/Jakarta. The 8-hour mark is 17:00; overtime is chosen at 17:02.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->desk = Desk::for($this, $this->person);
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:02');
});

test('3.5.1 the presence check is due after 60 minutes of untagged quiet time and overtime keeps running while it waits', function () {
    $this->desk->at('18:10');
    $this->desk->sync([$this->desk->event('idle_start', at: '18:00')])->assertOk();

    $this->desk->heartbeat('19:29')
        ->assertJsonPath('shift.status', 'overtime')
        ->assertJsonPath('shift.idle_periods.0.started_at', '2026-09-14T11:00:00.000Z')
        ->assertJsonPath('shift.idle_periods.0.ended_at', null);
});

test('3.5.2 answering "Masih lembur" keeps overtime running and the quiet period stays recorded', function () {
    $this->desk->at('18:10');
    $this->desk->sync([$this->desk->event('idle_start', at: '18:00')])->assertOk();
    $this->desk->send('idle_end', at: '19:05');
    $this->desk->send('presence_confirmed', at: '19:06');

    $this->desk->heartbeat('20:00')
        ->assertJsonPath('shift.status', 'overtime')
        ->assertJsonPath('shift.overtime.minutes', 180)
        ->assertJsonPath('shift.idle_periods.0.minutes', 65);
});

test('3.5.3 no answer within 30 minutes ends overtime at the start of the quiet period and moves the shift to report due', function () {
    $this->desk->at('18:10');
    $this->desk->sync([$this->desk->event('idle_start', at: '18:00')])->assertOk();

    $this->desk->heartbeat('19:31')->assertJsonPath('shift', null)->assertJsonPath('reports_due.0.overtime_minutes', 60);

    $this->artisan('attendance:settle')->assertSuccessful();
    $shift = $this->desk->shift();

    expect($shift->status)->toBe(ShiftStatus::ReportDue)
        ->and($shift->overtime_end_reason)->toBe(OvertimeEndReason::PresenceCheckNoAnswer)
        ->and($shift->end_reason)->toBe(EndReason::AutoNoAnswer)
        ->and($shift->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T11:00:00Z')
        ->and(OvertimeRequest::query()->sole()->ended_at->toIso8601ZuluString())->toBe('2026-09-14T11:00:00Z');
});

test('3.5.4 a quiet period tagged Render in advance does not trigger the check', function () {
    // The click on "Aku tinggal dulu" is the last input, so the backdated idle start can land a few ms before the tag (P3)
    $this->desk->send('idle_tag', ['tag' => 'rendering', 'pre_tag' => true], '18:00:00.400');
    $this->desk->at('18:10');
    $this->desk->sync([$this->desk->event('idle_start', at: '18:00:00.300')])->assertOk();

    $this->desk->heartbeat('23:00')
        ->assertJsonPath('shift.status', 'overtime')
        ->assertJsonPath('shift.idle_periods.0.tag', 'rendering')
        ->assertJsonPath('shift.overtime.minutes', 360);
});

test('3.5.4 a Render pre-tag sent a while before the quiet period starts also skips the check', function () {
    $this->desk->send('idle_tag', ['tag' => 'rendering', 'pre_tag' => true], '17:59');
    $this->desk->at('18:10');
    $this->desk->sync([$this->desk->event('idle_start', at: '18:00')])->assertOk();

    $this->desk->heartbeat('23:00')->assertJsonPath('shift.status', 'overtime');
});

test('3.1.3 moving the shift to another PC ends the first PC\'s open quiet period there, so it cannot end overtime (I15)', function () {
    $this->desk->at('18:10');
    $this->desk->sync([$this->desk->event('idle_start', at: '18:00')])->assertOk();

    // Left PC-ANIM-07 at 18.00 and carried on at the review PC from 18.20
    $pcB = Desk::for($this, $this->person, 'PC-REVIEW-01');
    $pcB->send('shift_moved', at: '18:20');
    $pcB->heartbeat('19:00');

    $pcB->heartbeat('19:31')
        ->assertJsonPath('shift.status', 'overtime')
        ->assertJsonPath('shift.device_id', $pcB->device->id)
        ->assertJsonPath('shift.overtime.minutes', 151)
        ->assertJsonPath('shift.idle_periods.0.started_at', '2026-09-14T11:00:00.000Z')
        ->assertJsonPath('shift.idle_periods.0.ended_at', '2026-09-14T11:20:00.000Z');

    $this->artisan('attendance:settle')->assertSuccessful();

    expect($this->desk->shift())
        ->status->toBe(ShiftStatus::Overtime)
        ->overtime_end_reason->toBeNull();
});

test('3.1.3 idle events the first PC still sends after the move are recorded but open no quiet period (I15)', function () {
    $pcB = Desk::for($this, $this->person, 'PC-REVIEW-01');
    $pcB->send('shift_moved', at: '18:20');

    // The app on PC-ANIM-07 keeps running with nobody at that desk
    $this->desk->at('18:40')->sync([$this->desk->event('idle_start', at: '18:30')])->assertOk();

    // Quiet time on the PC the shift runs on still leads to the check, and the first PC cannot end it
    $pcB->at('19:00')->sync([$pcB->event('idle_start', at: '18:50')])->assertOk();
    $this->desk->send('idle_end', at: '19:10');

    $pcB->heartbeat('20:19')
        ->assertJsonPath('shift.status', 'overtime')
        ->assertJsonCount(1, 'shift.idle_periods')
        ->assertJsonPath('shift.idle_periods.0.started_at', '2026-09-14T11:50:00.000Z')
        ->assertJsonPath('shift.idle_periods.0.ended_at', null);

    $pcB->heartbeat('20:21')->assertJsonPath('shift', null)->assertJsonPath('reports_due.0.overtime_minutes', 110);

    expect(AttendanceEvent::query()->where('device_id', $this->desk->device->id)->orderBy('occurred_at')->pluck('type')->map->value->all())
        ->toBe(['clock_in', 'overtime_start', 'idle_start', 'idle_end']);
});

test('3.5.5 a late claim covers the time after the automatic end', function () {
    $this->desk->at('18:10');
    $this->desk->sync([$this->desk->event('idle_start', at: '18:00')])->assertOk();
    $this->desk->heartbeat('19:40');
    $this->desk->send('clock_out', ['work_report' => 'Render selesai'], '21:00');
    $shift = $this->desk->shift();

    $this->desk->at('2026-09-15 09:00');
    $this->desk->request('POST', "/api/v1/overtime/{$shift->id}/claim", [
        'reason' => 'Masih di meja, cek render tiap jam',
        'work_report' => 'Render shot 12 dan 13 selesai',
        'ended_at' => '2026-09-14T14:00:00Z',
    ])->assertCreated()
        ->assertJsonPath('overtime_request.is_late_claim', true)
        ->assertJsonPath('overtime_request.minutes', 240)
        ->assertJsonPath('shift.status', 'closed');
});
