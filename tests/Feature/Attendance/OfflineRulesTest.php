<?php

use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Access\Role;
use Tests\Feature\Attendance\Support\Desk;

// Monday 2026-09-14, Asia/Jakarta.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->desk = Desk::for($this, $this->person);
});

test('3.8.1 events written to the outbox offline are accepted when the PC is back online', function () {
    $this->desk->at('2026-09-14 12:00');

    $this->desk->sync([
        $this->desk->event('clock_in', at: '09:00', overrides: ['offline' => true]),
        $this->desk->event('idle_start', at: '10:00', overrides: ['offline' => true]),
        $this->desk->event('idle_end', at: '10:20', overrides: ['offline' => true]),
        $this->desk->event('heartbeat', at: '11:59', overrides: ['offline' => true]),
    ])->assertOk()->assertJsonPath('shift.clock_in_at', '2026-09-14T02:00:00.000Z');

    expect(AttendanceEvent::query()->where('offline', true)->count())->toBe(3)
        ->and($this->desk->shift()->flags)->toBe(['offline_sign_in']);
});

test('3.8.2 sending the same event twice does not double count', function () {
    $this->desk->at('2026-09-14 12:00');
    $events = [
        $this->desk->event('clock_in', at: '09:00', overrides: ['offline' => true]),
        $this->desk->event('clock_out', at: '11:00', overrides: ['offline' => true]),
    ];

    $first = $this->desk->sync($events)->assertOk();
    $again = $this->desk->sync($events)->assertOk();

    expect($first->json('accepted'))->toBe(array_column($events, 'id'))
        ->and($again->json('accepted'))->toBe([])
        ->and($again->json('duplicates'))->toBe(array_column($events, 'id'))
        ->and(Shift::query()->count())->toBe(1)
        ->and(Shift::query()->sole()->regular_minutes)->toBe(120)
        ->and(AttendanceEvent::query()->count())->toBe(2);
});

test('3.8.3 events are processed in the order they happened, not the order they arrive', function () {
    $this->desk->at('2026-09-14 18:00');

    $this->desk->sync([
        $this->desk->event('clock_out', at: '16:00', overrides: ['offline' => true]),
        $this->desk->event('idle_end', at: '12:30', overrides: ['offline' => true]),
        $this->desk->event('clock_in', at: '09:00', overrides: ['offline' => true]),
        $this->desk->event('idle_start', at: '12:00', overrides: ['offline' => true]),
    ])->assertOk();

    $shift = $this->desk->shift();

    expect($shift->regular_minutes)->toBe(420)
        ->and($shift->idle_minutes)->toBe(30)
        ->and(AttendanceEvent::query()->whereNull('shift_id')->count())->toBe(0);
});

test('3.8.3 an older clock-in that arrives late gets the events after it', function () {
    $this->desk->at('2026-09-14 12:00');
    $pcB = Desk::for($this, $this->person, 'PC-B');

    $pcB->sync([$pcB->event('clock_in', at: '11:00', overrides: ['offline' => true])])->assertOk();
    $this->desk->sync([
        $this->desk->event('clock_in', at: '09:00', overrides: ['offline' => true]),
        $this->desk->event('idle_start', at: '10:00', overrides: ['offline' => true]),
        $this->desk->event('heartbeat', at: '10:59', overrides: ['offline' => true]),
    ])->assertOk();

    $shifts = Shift::query()->orderBy('clock_in_at')->get();

    // PC-B never heard of the offline shift, so the earlier one ends where PC-A was last seen, for review
    expect($shifts)->toHaveCount(2)
        ->and($shifts[0]->status->value)->toBe('needs_review')
        ->and($shifts[0]->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T03:59:00Z')
        ->and($shifts[0]->idle_minutes)->toBe(59)
        ->and($shifts[1]->clock_out_at)->toBeNull();
});

test('3.8.4 the response lists accepted ids so the app can count what is still unsent', function () {
    $this->desk->at('2026-09-14 09:00');
    $event = $this->desk->event('clock_in');

    $this->desk->sync([$event])->assertOk()
        ->assertJsonPath('accepted', [$event['id']])
        ->assertJsonPath('duplicates', []);
});

test('3.8.5 an online sign-in on a PC is recorded for the offline sign-in rule', function () {
    $this->travelTo(Desk::time('2026-09-14 08:00'));

    $this->postJson('/api/v1/auth/device-login', [
        'username' => $this->person->username,
        'password' => 'password-for-tests',
        'device_id' => 'PC-ANIM-09:77aa',
        'hostname' => 'PC-ANIM-09',
        'app_version' => '0.1.0',
    ])->assertOk();

    $pivot = $this->person->devices()->whereKey('PC-ANIM-09:77aa')->sole()->pivot;

    expect($pivot->last_online_sign_in_at)->toStartWith('2026-09-14 01:00:00')
        ->and($this->desk->request('GET', '/api/v1/config')->json('settings')['attendance.offline_sign_in_days'])->toBe(14);
});

test('3.8.6 the prompt timeout the PC applied offline is accepted when it syncs', function () {
    $this->desk->at('2026-09-14 18:00');

    $this->desk->sync([
        $this->desk->event('clock_in', at: '09:00', overrides: ['offline' => true]),
        $this->desk->event('regular_time_reached', at: '17:00', overrides: ['offline' => true]),
        $this->desk->event('auto_clock_out', at: '17:30', overrides: ['offline' => true]),
    ])->assertOk()->assertJsonPath('shift', null);

    expect($this->desk->shift()->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T10:00:00Z');
});
