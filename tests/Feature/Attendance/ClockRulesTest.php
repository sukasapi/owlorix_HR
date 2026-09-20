<?php

use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Identity\Access\Role;
use Tests\Feature\Attendance\Support\Desk;

// Monday 2026-09-14, Asia/Jakarta.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->desk = Desk::for($this, $this->person);
});

test('3.9.1 server time is the authority: an event cannot be stored later than the server received it', function () {
    $this->desk->at('2026-09-14 09:00');
    $this->desk->sync([$this->desk->event('clock_in', at: '09:45')])->assertOk();

    $event = AttendanceEvent::query()->sole();

    expect($event->occurred_at->toIso8601ZuluString())->toBe('2026-09-14T02:00:00Z')
        ->and($event->occurred_at_device->toIso8601ZuluString())->toBe('2026-09-14T02:45:00Z')
        ->and($event->received_at->toIso8601ZuluString())->toBe('2026-09-14T02:00:00Z');
});

test('3.9.2 each event carries the PC clock time, the uptime and the boot id', function (string $field) {
    $this->desk->at('2026-09-14 09:00');
    $event = $this->desk->event('clock_in');
    unset($event[$field]);

    $response = $this->desk->sync([$event])->assertOk();

    expect($response->json('rejected.0.code'))->toBe('invalid')
        ->and($response->json('rejected.0.errors'))->toHaveKey($field)
        ->and($response->json('accepted'))->toBe([]);
})->with(['occurred_at_device', 'uptime_ms', 'boot_id', 'server_offset_ms', 'offline']);

test('3.9.3 online events are corrected with the offset the app measured', function () {
    $this->desk->at('2026-09-14 09:00');
    // The PC clock is 5 minutes fast; the app knows the server is 300 000 ms behind it
    $this->desk->sync([$this->desk->event('clock_in', at: '09:05', overrides: ['server_offset_ms' => -300_000])])
        ->assertOk()
        ->assertJsonPath('shift.clock_in_at', '2026-09-14T02:00:00.000Z');
});

test('3.9.3 offline events are counted from the last online event with the uptime timer, ignoring a changed Windows clock', function () {
    $this->desk->at('2026-09-14 09:00');
    $clockIn = $this->desk->event('clock_in');
    $this->desk->sync([$clockIn])->assertOk();

    // Offline at 11:00 real time; someone set the Windows clock back to 08:00
    $this->desk->at('12:00');
    $idle = $this->desk->event('idle_start', at: '08:00', overrides: [
        'offline' => true,
        'uptime_ms' => $clockIn['uptime_ms'] + 2 * 3_600_000,
    ]);
    $this->desk->sync([$idle])->assertOk();

    expect(AttendanceEvent::query()->find($idle['id'])->occurred_at->toIso8601ZuluString())->toBe('2026-09-14T04:00:00Z')
        ->and($this->desk->shift()->flags)->toBe(['clock_mismatch']);
});

test('3.9.4 a PC clock more than 2 minutes off flags the shift clock_mismatch', function () {
    $this->desk->at('2026-09-14 09:00');
    // The Windows clock is 3 minutes behind; the app measured that against the server
    $this->desk->sync([$this->desk->event('clock_in', at: '08:57', overrides: ['server_offset_ms' => 180_000])])
        ->assertOk()
        ->assertJsonPath('shift.clock_in_at', '2026-09-14T02:00:00.000Z')
        ->assertJsonPath('shift.flags', ['clock_mismatch']);
});

test('3.9.4 a PC clock within 2 minutes is not flagged', function () {
    $this->desk->at('2026-09-14 09:00');
    $this->desk->sync([$this->desk->event('clock_in', at: '08:58:30', overrides: ['server_offset_ms' => 90_000])])->assertOk()->assertJsonPath('shift.flags', []);
});
