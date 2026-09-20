<?php

use App\Modules\Attendance\Enums\EndReason;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Calendar\Enums\CalendarDayType;
use App\Modules\Calendar\Events\CalendarDatesChanged;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Identity\Access\Role;
use App\Modules\Overtime\Models\OvertimeRequest;
use Tests\Feature\Attendance\Support\Desk;

// Monday 2026-09-14, Asia/Jakarta.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->desk = Desk::for($this, $this->person);
});

test('3.3.1 regular time is added up across the shifts of a work date', function () {
    $this->desk->send('clock_in', at: '2026-09-14 07:00');
    $this->desk->send('clock_out', at: '10:00');
    $this->desk->send('clock_in', at: '11:00')
        ->assertJsonPath('shift.regular_before_minutes', 180)
        ->assertJsonPath('shift.regular_ends_at', '2026-09-14T09:00:00.000Z');
});

test('3.3.1 regular time is saved once the limit is reached, whatever happens next', function () {
    $this->desk->send('clock_in', at: '2026-09-14 08:00');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '16:05');
    $this->desk->send('pc_shutdown', at: '17:00');

    $this->desk->at('2026-09-14 19:00');
    $this->artisan('attendance:settle')->assertSuccessful();

    $shift = $this->desk->shift();

    expect($shift->status)->toBe(ShiftStatus::NeedsReview)
        ->and($shift->regular_minutes)->toBe(480)
        ->and($shift->overtime_minutes)->toBe(60);

    // A sleep recorded offline before the mark arrives after the limit was saved: it is recorded, but does not lower
    // the saved regular time (3.3.1)
    $this->desk->at('19:05');
    $this->desk->sync([
        $this->desk->event('pc_sleep', at: '12:00', overrides: ['offline' => true]),
        $this->desk->event('pc_wake', at: '12:40', overrides: ['offline' => true]),
    ])->assertOk();

    expect($this->desk->shift())
        ->regular_minutes->toBe(480)
        ->interruption_minutes->toBe(40)
        ->is_short->toBeFalse();

    // A calendar change is an explicit recalculation (3.2.3) and may lower it
    CalendarDay::query()->create(['date' => '2026-09-14', 'type' => CalendarDayType::Holiday, 'name' => 'Libur susulan', 'created_by' => userWithRole(Role::Superadmin)->id]);
    CalendarDatesChanged::dispatch(['2026-09-14']);

    expect($this->desk->shift())
        ->regular_minutes->toBe(0)
        ->overtime_minutes->toBe(500);
});

test('3.3.2 the server marks the shift as prompted at the 8-hour mark and gives the answer deadline', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');

    $state = $this->desk->heartbeat('17:00')->json();

    expect($state['shift']['status'])->toBe('prompted')
        ->and($state['shift']['regular_ends_at'])->toBe('2026-09-14T10:00:00.000Z')
        ->and($state['shift']['prompt_deadline_at'])->toBe('2026-09-14T10:30:00.000Z')
        ->and($state['regular_minutes'])->toBe(480);
});

test('3.3.3 clock out in the prompt closes the shift at the click and the answer time is not overtime', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('clock_out', at: '17:14')->assertJsonPath('shift', null);

    $shift = $this->desk->shift();

    expect($shift->status)->toBe(ShiftStatus::Closed)
        ->and($shift->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T10:14:00Z')
        ->and($shift->regular_minutes)->toBe(480)
        ->and($shift->overtime_minutes)->toBe(0)
        ->and(OvertimeRequest::query()->count())->toBe(0);
});

test('3.3.4 keep working starts overtime at the 8-hour mark with the reason', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:09')
        ->assertJsonPath('shift.status', 'overtime')
        ->assertJsonPath('shift.overtime.started_at', '2026-09-14T10:00:00.000Z')
        ->assertJsonPath('shift.overtime.minutes', 9)
        ->assertJsonPath('shift.overtime.reason', 'Render final shot 12');
});

test('3.3.5 no answer for 30 minutes closes the shift at the 8-hour mark', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('17:29')->assertJsonPath('shift.status', 'prompted');

    $this->desk->heartbeat('17:31')->assertJsonPath('shift', null)->assertJsonPath('regular_minutes', 480);

    $this->artisan('attendance:settle')->assertSuccessful();
    $shift = $this->desk->shift();

    expect($shift->status)->toBe(ShiftStatus::Closed)
        ->and($shift->end_reason)->toBe(EndReason::AutoNoAnswer)
        ->and($shift->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T10:00:00Z')
        ->and($shift->overtime_minutes)->toBe(0);
});

test('3.3.5 the auto_clock_out event the app records closes the shift at the 8-hour mark', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('auto_clock_out', at: '17:30')->assertJsonPath('shift', null);

    expect($this->desk->shift()->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T10:00:00Z')
        ->and($this->desk->shift()->end_reason)->toBe(EndReason::AutoNoAnswer);
});

test('3.3.6 a late overtime claim for an auto-closed shift within 24 hours is marked late claim', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('17:40');
    $this->desk->heartbeat('19:05');
    $shift = $this->desk->shift();

    $this->desk->at('2026-09-15 08:30');
    $this->desk->request('POST', "/api/v1/overtime/{$shift->id}/claim", [
        'reason' => 'Masih render, prompt tidak terlihat',
        'work_report' => 'Render shot 7 dan 8',
        'ended_at' => '2026-09-14T12:00:00Z',
    ])->assertCreated()
        ->assertJsonPath('overtime_request.status', 'pending')
        ->assertJsonPath('overtime_request.is_late_claim', true)
        ->assertJsonPath('overtime_request.minutes', 120)
        ->assertJsonPath('shift.flags', ['late_claim']);

    $request = OvertimeRequest::query()->sole();

    expect($request->started_at->toIso8601ZuluString())->toBe('2026-09-14T10:00:00Z')
        ->and($request->reason)->toBe('Masih render, prompt tidak terlihat')
        ->and($request->work_report)->toBe('Render shot 7 dan 8');
});

test('3.3.6 a late claim after 24 hours is refused', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('17:40');
    $shift = $this->desk->shift();

    $this->desk->at('2026-09-15 17:01');
    $this->desk->request('POST', "/api/v1/overtime/{$shift->id}/claim", [
        'reason' => 'Masih render, prompt tidak terlihat',
        'work_report' => 'Render shot 7 dan 8',
        'ended_at' => '2026-09-14T12:00:00Z',
    ])->assertStatus(422)->assertJsonPath('code', 'claim_window_closed');

    expect(OvertimeRequest::query()->count())->toBe(0);
});
