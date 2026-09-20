<?php

use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\Device;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Feature\Attendance\Support\Desk;

// 2026-09-14 is a Monday, 2026-09-19 a Saturday. Times are Asia/Jakarta.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->desk = Desk::for($this, $this->person, 'PC-ANIM-07');
});

test('3.1.1 any account signs in on any studio PC and gets a token for that PC', function () {
    foreach (['PC-ANIM-07:aa11', 'PC-LIGHT-02:bb22'] as $deviceId) {
        $this->postJson('/api/v1/auth/device-login', [
            'username' => $this->person->username,
            'password' => 'password-for-tests',
            'device_id' => $deviceId,
            'hostname' => explode(':', $deviceId)[0],
            'app_version' => '0.1.0',
        ])->assertOk()->assertJsonPath('user.id', $this->person->id);
    }

    expect($this->person->tokens()->pluck('name')->sort()->values()->all())->toContain('PC-ANIM-07:aa11', 'PC-LIGHT-02:bb22')
        ->and(Device::query()->whereIn('id', ['PC-ANIM-07:aa11', 'PC-LIGHT-02:bb22'])->count())->toBe(2);
});

test('3.1.2 a clock_in event opens a shift', function () {
    $response = $this->desk->send('clock_in', at: '2026-09-14 09:02');

    $response->assertJsonPath('shift.status', 'open')
        ->assertJsonPath('shift.clock_in_at', '2026-09-14T02:02:00.000Z')
        ->assertJsonPath('shift.regular_ends_at', '2026-09-14T10:02:00.000Z');

    $shift = $this->desk->shift();

    expect($shift->status)->toBe(ShiftStatus::Open)
        ->and($shift->work_date)->toBe('2026-09-14')
        ->and($shift->regular_limit_minutes)->toBe(480)
        ->and(AttendanceEvent::query()->where('shift_id', $shift->id)->where('type', 'clock_in')->exists())->toBeTrue();
});

test('3.1.2 undo within 2 minutes removes the shift from totals and keeps the events', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('clock_in_cancelled', at: '09:01:50')->assertJsonPath('shift', null)->assertJsonPath('regular_minutes', 0);

    expect(Shift::query()->where('user_id', $this->person->id)->count())->toBe(0)
        ->and(AttendanceEvent::query()->where('user_id', $this->person->id)->whereNull('shift_id')->pluck('type')->map->value->all())
        ->toBe(['clock_in', 'clock_in_cancelled']);
});

test('3.1.2 an undo after 2 minutes is ignored', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('clock_in_cancelled', at: '09:02:30')->assertJsonPath('shift.status', 'open');

    expect(Shift::query()->where('user_id', $this->person->id)->count())->toBe(1);
});

test('3.1.3 one person has one open shift; moving to another PC keeps the same shift', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $pcB = Desk::for($this, $this->person, 'PC-REVIEW-01');

    $pcB->send('shift_moved', at: '11:00')
        ->assertJsonPath('shift.device_id', $pcB->device->id)
        ->assertJsonPath('shift.interruption_minutes', 0)
        ->assertJsonPath('shift.regular_minutes', 120);

    expect(Shift::query()->where('user_id', $this->person->id)->count())->toBe(1);
});

test('3.1.3 the database refuses a second shift without a clock-out for the same person', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $shift = $this->desk->shift();

    expect(fn () => Shift::query()->create([
        ...$shift->only(['user_id', 'work_date', 'is_workday', 'clock_in_at', 'last_seen_at', 'regular_limit_minutes']),
        'status' => ShiftStatus::Open,
        'flags' => [],
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('3.1.4 another person signing in on the same PC does not clock out the first person', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $colleague = userWithRole(Role::Employee);

    $this->postJson('/api/v1/auth/device-login', [
        'username' => $colleague->username,
        'password' => 'password-for-tests',
        'device_id' => $this->desk->device->id,
        'hostname' => 'PC-ANIM-07',
        'app_version' => '0.1.0',
    ])->assertOk();

    $this->desk->samePcFor($colleague)->send('clock_in', at: '10:00')->assertJsonPath('shift.status', 'open');

    // The first person's app is signed out, so their heartbeats stop: the shift is interrupted, not clocked out (3.7)
    expect($this->desk->shift()->clock_out_at)->toBeNull()
        ->and($this->desk->state('10:01')['shift']['status'])->toBe('interrupted');

    $this->desk->send('shift_resumed', at: '10:20')->assertJsonPath('shift.status', 'open');
});

test('3.1.5 on a workday with regular time left the shift opens normally', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00')
        ->assertJsonPath('is_workday', true)
        ->assertJsonPath('shift.status', 'open')
        ->assertJsonPath('regular_minutes', 0);
});

test('3.1.5 on a workday where 8 hours are already reached the prompt shows right away', function () {
    $this->desk->send('clock_in', at: '2026-09-14 08:00');
    $this->desk->send('clock_out', at: '16:00');

    $this->desk->send('clock_in', at: '19:00')
        ->assertJsonPath('shift.status', 'prompted')
        ->assertJsonPath('shift.regular_ends_at', null)
        ->assertJsonPath('shift.prompt_deadline_at', '2026-09-14T12:30:00.000Z')
        ->assertJsonPath('regular_minutes', 480);
});

test('3.1.5 on a non-workday the shift opens as overtime with the reason asked at sign-in', function () {
    $this->desk->send('clock_in', ['reason' => 'Revisi klien untuk Senin'], '2026-09-19 10:00')
        ->assertJsonPath('is_workday', false)
        ->assertJsonPath('shift.status', 'overtime')
        ->assertJsonPath('shift.overtime.started_at', '2026-09-19T03:00:00.000Z')
        ->assertJsonPath('shift.overtime.reason', 'Revisi klien untuk Senin')
        ->assertJsonPath('shift.overtime.status', 'pending');
});
