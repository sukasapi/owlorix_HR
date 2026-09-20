<?php

use App\Modules\Attendance\Enums\EndReason;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Identity\Access\Role;
use Tests\Feature\Attendance\Support\Desk;

// Monday 2026-09-14, Asia/Jakarta.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->desk = Desk::for($this, $this->person);
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
});

test('3.7.1 a shutdown or sleep notice interrupts the shift', function (string $type) {
    $this->desk->send($type, at: '12:00')->assertJsonPath('shift.status', 'interrupted');
})->with(['pc_shutdown', 'pc_sleep']);

test('3.7.2 heartbeats update last seen on the shift and the device and are not stored', function () {
    $this->desk->heartbeat('11:00')->assertJsonPath('shift.last_seen_at', '2026-09-14T04:00:00.000Z');

    expect($this->desk->shift()->last_seen_at->toIso8601ZuluString())->toBe('2026-09-14T04:00:00Z')
        ->and($this->desk->device->refresh()->last_seen_at->toIso8601ZuluString())->toBe('2026-09-14T04:00:00Z')
        ->and(AttendanceEvent::query()->where('type', 'heartbeat')->exists())->toBeFalse()
        ->and($this->desk->request('GET', '/api/v1/config')->json('settings')['sync.heartbeat_upload_seconds'])->toBe(120);
});

test('3.7.3 the same person back within 90 minutes continues the shift with the gap recorded as an interruption', function () {
    $this->desk->send('pc_shutdown', at: '14:00');

    $this->desk->send('shift_resumed', at: '15:25')
        ->assertJsonPath('shift.status', 'open')
        ->assertJsonPath('shift.interruption_minutes', 85)
        ->assertJsonPath('shift.interruptions.0.started_at', '2026-09-14T07:00:00.000Z')
        ->assertJsonPath('shift.regular_ends_at', '2026-09-14T11:25:00.000Z');
});

test('3.7.3 without a return within 90 minutes the shift is closed at the last heartbeat and flagged needs review', function () {
    $this->desk->heartbeat('13:58');
    $this->desk->send('pc_shutdown', at: '14:00');

    expect($this->desk->state('15:31')['shift'])->toBeNull();

    $this->artisan('attendance:settle');
    $shift = $this->desk->shift();

    expect($shift->status)->toBe(ShiftStatus::NeedsReview)
        ->and($shift->end_reason)->toBe(EndReason::ShutdownTimeout)
        ->and($shift->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T07:00:00Z')
        ->and($shift->regular_minutes)->toBe(300);

    $this->desk->send('clock_in', at: '15:40')->assertJsonPath('shift.status', 'open');
});

test('3.7.4 when someone else used the PC, the shift follows 3.7.3 when the person signs in again anywhere', function () {
    $this->desk->heartbeat('12:00');
    $colleague = userWithRole(Role::Employee);
    $this->desk->samePcFor($colleague)->send('clock_in', at: '12:05');

    $elsewhere = Desk::for($this, $this->person, 'PC-LIGHT-02');
    $elsewhere->send('shift_resumed', at: '13:00')
        ->assertJsonPath('shift.status', 'open')
        ->assertJsonPath('shift.device_id', $elsewhere->device->id)
        ->assertJsonPath('shift.interruption_minutes', 60);
});
