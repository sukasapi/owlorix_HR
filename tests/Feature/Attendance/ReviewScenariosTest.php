<?php

use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Events\ShiftRecalculated;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Access\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Attendance\Support\Desk;

// Scenarios from the independent review of the attendance engine. Monday 2026-09-14, Asia/Jakarta.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->desk = Desk::for($this, $this->person);
});

/** @return array<string, mixed> */
function offlineEvent(Desk $desk, string $type, string $at, array $payload = []): array
{
    return $desk->event($type, $payload, $at, ['offline' => true]);
}

test('3.7.3 a wake on the same PC within the resume window continues the shift', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('pc_sleep', at: '12:00');
    $this->desk->send('pc_wake', at: '12:20');

    foreach (['12:40', '13:00', '13:20', '13:40', '14:00'] as $beat) {
        $this->desk->heartbeat($beat);
    }

    $shift = $this->desk->state('14:01')['shift'];

    expect($shift['status'])->toBe('open')
        ->and($shift['interruption_minutes'])->toBe(20)
        ->and($shift['regular_minutes'])->toBe(281);
});

test('3.7.3 heartbeats alone do not end a sleep: the app runs, but nobody woke the PC through it', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('pc_sleep', at: '12:00');
    $this->desk->heartbeat('12:30');
    $this->desk->heartbeat('13:00');

    expect($this->desk->heartbeat('13:31')->json('shift'))->toBeNull();
});

test('3.7.3 a quick app restart is not an interruption', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('11:58');

    $this->desk->send('shift_resumed', at: '12:00:30')
        ->assertJsonPath('shift.interruption_minutes', 0)
        ->assertJsonPath('shift.interruptions', []);
});

test('3.7.3 an offline crash resumed by the PC keeps the day and flags the gap the server could not see (Q1)', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('09:10');

    $this->desk->at('16:05');
    $this->desk->sync([
        offlineEvent($this->desk, 'shift_resumed', '12:30'),
        offlineEvent($this->desk, 'clock_out', '16:00'),
    ])->assertOk();

    $shift = $this->desk->shift();

    expect($shift->status)->toBe(ShiftStatus::Closed)
        ->and($shift->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T09:00:00Z')
        ->and($shift->interruption_minutes)->toBe(200)
        ->and($shift->regular_minutes)->toBe(220)
        ->and($shift->flags)->toBe(['gap_unverified']);
});

test('3.7.3 a crash gap is measured from the last event the PC recorded offline (P1)', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('09:10');

    $this->desk->at('16:05');
    $this->desk->sync([
        offlineEvent($this->desk, 'idle_start', '10:40'),
        offlineEvent($this->desk, 'idle_end', '11:05'),
        offlineEvent($this->desk, 'shift_resumed', '12:30'),
        offlineEvent($this->desk, 'clock_out', '16:00'),
    ])->assertOk();

    expect($this->desk->shift())
        ->status->toBe(ShiftStatus::Closed)
        ->interruption_minutes->toBe(85)
        ->regular_minutes->toBe(335)
        ->flags->toBe([]);
});

test('3.7.3 a crash gap starts at the last local heartbeat the PC reports with the resume', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('09:10');

    $this->desk->at('12:31');
    $this->desk->sync([
        offlineEvent($this->desk, 'shift_resumed', '12:30', ['last_heartbeat_at' => Desk::time('12:10')->format('Y-m-d\TH:i:s.v\Z')]),
    ])->assertOk()->assertJsonPath('shift.interruption_minutes', 20)->assertJsonPath('shift.flags', []);
});

test('3.8 offline events reopen a shift that was closed for missing heartbeats', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('09:10');

    $this->desk->at('11:00');
    $this->artisan('attendance:settle');
    expect($this->desk->shift()->status)->toBe(ShiftStatus::NeedsReview);

    $this->desk->at('12:00');
    $this->desk->sync([
        offlineEvent($this->desk, 'idle_start', '10:00'),
        offlineEvent($this->desk, 'idle_end', '10:30'),
        offlineEvent($this->desk, 'heartbeat', '11:59'),
    ])->assertOk()->assertJsonPath('shift.status', 'open');

    expect($this->desk->shift())->clock_out_at->toBeNull()->status->toBe(ShiftStatus::Open);
});

test('3.1.2 undoing a clock-in on another PC reopens the shift it closed (P2)', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('12:58');

    $pcB = Desk::for($this, $this->person, 'PC-B');
    $pcB->send('clock_in', at: '13:00');
    $pcB->send('clock_in_cancelled', at: '13:01')->assertJsonPath('shift.status', 'open');

    $shift = Shift::query()->sole();

    expect($shift->clock_out_at)->toBeNull()
        ->and($shift->status)->toBe(ShiftStatus::Open)
        ->and($shift->clock_in_at->toIso8601ZuluString())->toBe('2026-09-14T02:00:00Z');
});

test('3.1.2 undoing a clock-in that closed a shift of the previous work date restores that shift with its events', function () {
    $this->desk->send('clock_in', at: '2026-09-14 22:00');
    $this->desk->heartbeat('2026-09-15 00:25');

    $pcB = Desk::for($this, $this->person, 'PC-B');
    $pcB->send('clock_in', at: '00:30');

    $this->desk->at('00:43');
    $this->desk->sync([$this->desk->event('idle_start', at: '00:33')])->assertOk();

    $pcB->at('00:44');
    $pcB->sync([offlineEvent($pcB, 'clock_in_cancelled', '00:31')])->assertOk();
    $this->desk->heartbeat('00:45')->assertJsonPath('shift.status', 'open')->assertJsonPath('shift.regular_minutes', 165);
    $this->artisan('attendance:settle');

    $shift = Shift::query()->sole();

    expect($shift->work_date)->toBe('2026-09-14')
        ->and($shift->clock_out_at)->toBeNull()
        ->and($shift->regular_minutes)->toBe(165)
        ->and($shift->idle_minutes)->toBe(12)
        ->and(AttendanceEvent::query()->where('type', 'idle_start')->value('shift_id'))->toBe($shift->id);
});

test('3.8 an event the server cannot process is rejected on its own and the rest of the batch is kept', function () {
    Event::listen(ShiftRecalculated::class, function (ShiftRecalculated $event) {
        foreach ($event->result->idlePeriods as $period) {
            if ($period->note === 'rusak') {
                throw new UniqueConstraintViolationException('mysql', 'insert', [], new PDOException('duplicate'));
            }
        }
    });

    $this->desk->at('2026-09-14 12:00');
    $broken = offlineEvent($this->desk, 'idle_tag', '10:11', ['tag' => 'other', 'note' => 'rusak']);

    $response = $this->desk->sync([
        offlineEvent($this->desk, 'clock_in', '09:00'),
        offlineEvent($this->desk, 'idle_start', '10:00'),
        offlineEvent($this->desk, 'idle_end', '10:10'),
        $broken,
        offlineEvent($this->desk, 'heartbeat', '11:59'),
    ])->assertOk();

    expect($response->json('rejected'))->toBe([['id' => $broken['id'], 'index' => 3, 'code' => 'unprocessable']])
        ->and($response->json('accepted'))->toHaveCount(4)
        ->and($response->json('shift.idle_minutes'))->toBe(10)
        ->and(AttendanceEvent::query()->count())->toBe(3);
});

test('3.8.2 an event id already used by another person is rejected, not reported as a duplicate', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $used = AttendanceEvent::query()->sole()->id;

    $colleague = Desk::for($this, userWithRole(Role::Employee), 'PC-ANIM-08');
    $response = $colleague->sync([$colleague->event('clock_in', overrides: ['id' => $used])])->assertOk();

    expect($response->json('duplicates'))->toBe([])
        ->and($response->json('rejected'))->toBe([['id' => $used, 'index' => 0, 'code' => 'id_conflict']])
        ->and(Shift::query()->where('user_id', $colleague->user->id)->exists())->toBeFalse();
});

test('3.8 a malformed event is rejected with its errors and valid events are kept', function () {
    $this->desk->at('2026-09-14 09:00');
    $bad = $this->desk->event('clock_in');
    unset($bad['boot_id']);

    $response = $this->desk->sync([$bad, $this->desk->event('clock_in')])->assertOk();

    expect($response->json('rejected.0.code'))->toBe('invalid')
        ->and($response->json('rejected.0.errors'))->toHaveKey('boot_id')
        ->and($response->json('accepted'))->toHaveCount(1)
        ->and($response->json('shift.status'))->toBe('open');
});

test('3.9.4 an online event dated far before it was sent is flagged and stored at arrival time (P5)', function () {
    $this->desk->at('2026-09-14 10:00');

    $this->desk->sync([$this->desk->event('clock_in', at: '08:00')])
        ->assertOk()
        ->assertJsonPath('shift.clock_in_at', '2026-09-14T03:00:00.000Z')
        ->assertJsonPath('shift.flags', ['clock_mismatch']);
});

test('3.9.4 a backdated idle start sent when the idle threshold passes is not a clock mismatch', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->at('10:10');

    $this->desk->sync([$this->desk->event('idle_start', at: '10:00')])
        ->assertJsonPath('shift.idle_periods.0.started_at', '2026-09-14T03:00:00.000Z')
        ->assertJsonPath('shift.flags', []);
});

test('3.4.2 overtime ended by a shutdown still asks for the work report at the next sign-in (Q3)', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:02');
    $this->desk->send('pc_shutdown', at: '20:00');
    $shiftId = $this->desk->shift()->id;

    $state = $this->desk->send('clock_in', at: '2026-09-15 09:00')->json();

    expect($state['reports_due'])->toHaveCount(1)
        ->and($state['reports_due'][0]['shift_id'])->toBe($shiftId)
        ->and($state['reports_due'][0]['status'])->toBe('needs_review')
        ->and($state['reports_due'][0]['overtime_minutes'])->toBe(180);
});

test('3.3.6 a late claim cannot end after the last activity recorded on the PC (Q2)', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('17:04');
    $this->desk->send('pc_shutdown', at: '17:05');
    $id = $this->desk->shift()->id;

    $this->desk->at('2026-09-15 08:00');
    expect($this->desk->state()['late_claims'][0])->toMatchArray([
        'shift_id' => $id,
        'end_reason' => 'auto_no_answer',
        'auto_ended_at' => '2026-09-14T10:00:00.000Z',
        'claimable_until' => '2026-09-15T10:00:00.000Z',
        'latest_end_at' => '2026-09-14T10:05:00.000Z',
    ]);

    $this->desk->request('POST', "/api/v1/overtime/{$id}/claim", [
        'reason' => 'Masih render, prompt tidak terlihat',
        'work_report' => 'Render shot 7',
        'ended_at' => '2026-09-15T09:45:00Z',
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'after_last_activity')
        ->assertJsonPath('latest_end_at', '2026-09-14T10:05:00.000Z');
});

test('3.5.3 a clock-out after an unanswered presence check tells the app the late claim it can file (P7)', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:02');
    $this->desk->at('18:10');
    $this->desk->sync([$this->desk->event('idle_start', at: '18:00')])->assertOk();
    $this->desk->send('idle_end', at: '19:10');

    $state = $this->desk->send('clock_out', ['work_report' => 'Render selesai'], '22:00')->json();

    expect($state['shift'])->toBeNull()
        ->and($state['late_claims'][0])->toMatchArray([
            'shift_id' => $this->desk->shift()->id,
            'overtime_end_reason' => 'presence_check_no_answer',
            'auto_ended_at' => '2026-09-14T11:00:00.000Z',
            'latest_end_at' => '2026-09-14T15:00:00.000Z',
        ]);
});
