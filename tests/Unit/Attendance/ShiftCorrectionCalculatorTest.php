<?php

use App\Modules\Attendance\Calculation\ShiftCalculator;
use App\Modules\Attendance\Calculation\ShiftEvent;
use App\Modules\Attendance\Calculation\ShiftInput;
use App\Modules\Attendance\Calculation\ShiftResult;
use App\Modules\Attendance\Enums\EndReason;
use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Enums\ShiftStatus;
use Carbon\CarbonImmutable;

// 3.10: a `correction_applied` event overrides one shift time and the minutes are counted again.
// Times are Asia/Jakarta on Monday 2026-09-14 unless a date is given.

function corrTime(string $time): CarbonImmutable
{
    return CarbonImmutable::parse(str_contains($time, '-') ? $time : "2026-09-14 {$time}", 'Asia/Jakarta')->utc();
}

/** @param array<string, mixed> $payload */
function corrEvent(string $type, string $at, array $payload = []): ShiftEvent
{
    static $sequence = 0;
    $sequence++;

    return new ShiftEvent(sprintf('corr-%05d', $sequence), EventType::from($type), corrTime($at), corrTime($at), 'PC-A', $payload);
}

/** A correction written by the server at $appliedAt (default: the next morning). */
function corrApplied(string $field, string $value, string $appliedAt = '2026-09-15 09:00'): ShiftEvent
{
    return corrEvent('correction_applied', $appliedAt, ['field' => $field, 'value' => corrTime($value)->format('Y-m-d\TH:i:s.v\Z')]);
}

/** @param list<ShiftEvent> $events */
function corrShift(string $clockIn, array $events, string $now = '2026-09-15 10:00', ?string $lastSeen = null, bool $workday = true): ShiftResult
{
    return (new ShiftCalculator)->calculate(new ShiftInput(
        clockIn: corrEvent('clock_in', $clockIn),
        events: $events,
        isWorkday: $workday,
        regularLimitMinutes: 480,
        regularBeforeMinutes: 0,
        lastSeenAt: corrTime($lastSeen ?? $now),
        now: corrTime($now),
    ));
}

it('counts regular time from a corrected clock-in', function () {
    $result = corrShift('09:30', [corrEvent('clock_out', '16:00'), corrApplied('clock_in_at', '09:00')]);

    expect($result->clockInAt)->toEqual(corrTime('09:00'))
        ->and($result->regularMinutes)->toBe(420)
        ->and($result->regularEndsAt)->toEqual(corrTime('17:00'))
        ->and($result->endReason)->toBe(EndReason::Manual);
});

it('moves the 8-hour mark and overtime that started there with a corrected clock-in', function () {
    // The prompt was answered 10 minutes after the recorded mark (17.30); the answer keeps counting
    $result = corrShift('09:30', [
        corrEvent('overtime_start', '17:40', ['reason' => 'Render final shot 12']),
        corrEvent('clock_out', '21:00', ['work_report' => 'Render selesai']),
        corrApplied('clock_in_at', '08:30'),
    ]);

    expect($result->regularMinutes)->toBe(480)
        ->and($result->regularEndsAt)->toEqual(corrTime('16:30'))
        ->and($result->overtime->startedAt)->toEqual(corrTime('16:30'))
        ->and($result->overtimeMinutes)->toBe(270)
        ->and($result->status)->toBe(ShiftStatus::Closed);

    $later = corrShift('09:30', [
        corrEvent('overtime_start', '17:40', ['reason' => 'Render final shot 12']),
        corrEvent('clock_out', '21:00', ['work_report' => 'Render selesai']),
        corrApplied('clock_in_at', '10:00'),
    ]);

    expect($later->overtime->startedAt)->toEqual(corrTime('18:00'))
        ->and($later->overtimeMinutes)->toBe(180);
});

it('ends the shift at a corrected clock-out later than the recorded one, without making overtime', function () {
    $result = corrShift('09:00', [corrEvent('clock_out', '12:00'), corrApplied('clock_out_at', '18:00')]);

    expect($result->clockOutAt)->toEqual(corrTime('18:00'))
        ->and($result->endReason)->toBe(EndReason::Superadmin)
        ->and($result->status)->toBe(ShiftStatus::Closed)
        ->and($result->regularMinutes)->toBe(480)
        ->and($result->regularEndsAt)->toEqual(corrTime('17:00'))
        ->and($result->overtimeMinutes)->toBe(0)
        ->and($result->overtime)->toBeNull();
});

it('ends the shift at a corrected clock-out earlier than the automatic close at the mark', function () {
    $result = corrShift('09:00', [corrApplied('clock_out_at', '15:00')], lastSeen: '17:40');

    expect($result->clockOutAt)->toEqual(corrTime('15:00'))
        ->and($result->regularMinutes)->toBe(360)
        ->and($result->claimableUntil)->toBeNull();
});

it('counts the time after a close for missing heartbeats up to a corrected clock-out and clears the review', function () {
    $recorded = corrShift('09:00', [], lastSeen: '12:00');

    expect($recorded->status)->toBe(ShiftStatus::NeedsReview)
        ->and($recorded->regularMinutes)->toBe(180);

    $result = corrShift('09:00', [corrApplied('clock_out_at', '15:00')], lastSeen: '12:00');

    expect($result->status)->toBe(ShiftStatus::Closed)
        ->and($result->regularMinutes)->toBe(360)
        ->and($result->interruptionMinutes)->toBe(0);
});

it('keeps recorded interruptions uncounted inside a corrected shift (I2)', function () {
    $result = corrShift('09:00', [
        corrEvent('pc_shutdown', '12:00'),
        corrEvent('shift_resumed', '12:40'),
        corrEvent('clock_out', '15:00'),
        corrApplied('clock_out_at', '18:00'),
    ]);

    expect($result->interruptionMinutes)->toBe(40)
        ->and($result->regularMinutes)->toBe(480)
        ->and($result->regularEndsAt)->toEqual(corrTime('17:40'));
});

it('starts overtime at a corrected time, but never before the 8-hour mark', function (string $start, int $minutes) {
    $result = corrShift('09:00', [
        corrEvent('overtime_start', '17:05', ['reason' => 'Render final shot 12']),
        corrEvent('clock_out', '19:00', ['work_report' => 'Render selesai']),
        corrApplied('overtime_started_at', $start),
    ]);

    expect($result->overtimeMinutes)->toBe($minutes)
        ->and($result->regularMinutes)->toBe(480)
        ->and($result->overtime->reason)->toBe('Render final shot 12');
})->with([
    'after the mark' => ['17:30', 90],
    'before the mark' => ['16:00', 120],
]);

it('adds overtime to a shift closed at the mark with corrected overtime start and clock-out', function () {
    $result = corrShift('09:00', [
        corrApplied('clock_out_at', '19:00'),
        corrApplied('overtime_started_at', '17:00', '2026-09-15 09:01'),
    ], lastSeen: '19:00');

    expect($result->clockOutAt)->toEqual(corrTime('19:00'))
        ->and($result->overtimeMinutes)->toBe(120)
        ->and($result->overtime->startedAt)->toEqual(corrTime('17:00'))
        ->and($result->overtime->endedAt)->toEqual(corrTime('19:00'))
        ->and($result->status)->toBe(ShiftStatus::ReportDue);
});

it('ends overtime at a corrected time and keeps the clock-out', function () {
    $result = corrShift('09:00', [
        corrEvent('overtime_start', '17:02', ['reason' => 'Revisi klien']),
        corrEvent('clock_out', '21:00', ['work_report' => 'Revisi selesai']),
        corrApplied('overtime_ended_at', '20:00'),
    ]);

    expect($result->overtimeMinutes)->toBe(180)
        ->and($result->clockOutAt)->toEqual(corrTime('21:00'))
        ->and($result->overtime->endedAt)->toEqual(corrTime('20:00'));
});

it('moves overtime with a corrected clock-out and drops it when the shift now ends before it', function () {
    $events = [
        corrEvent('overtime_start', '17:02', ['reason' => 'Revisi klien']),
        corrEvent('clock_out', '21:00', ['work_report' => 'Revisi selesai']),
    ];

    expect(corrShift('09:00', [...$events, corrApplied('clock_out_at', '22:00')])->overtimeMinutes)->toBe(300)
        ->and(corrShift('09:00', [...$events, corrApplied('clock_out_at', '19:00')])->overtimeMinutes)->toBe(120);

    $dropped = corrShift('09:00', [...$events, corrApplied('clock_out_at', '16:00')]);

    expect($dropped->overtime)->toBeNull()
        ->and($dropped->regularMinutes)->toBe(420)
        ->and($dropped->status)->toBe(ShiftStatus::Closed);
});

it('counts a corrected non-workday shift as overtime from the corrected times', function () {
    $result = corrShift('10:00', [
        corrEvent('clock_out', '12:00', ['work_report' => 'Revisi selesai']),
        corrApplied('clock_in_at', '09:00'),
        corrApplied('clock_out_at', '13:00'),
    ], workday: false);

    expect($result->overtimeMinutes)->toBe(240)
        ->and($result->overtime->startedAt)->toEqual(corrTime('09:00'))
        ->and($result->regularMinutes)->toBe(0);
});

it('leaves a running shift as its device shows it until it ends, then applies the corrections', function () {
    $events = [corrApplied('clock_in_at', '08:00', '10:00'), corrApplied('clock_out_at', '12:00', '11:00')];
    $running = corrShift('09:00', $events, now: '11:30');

    expect($running->isLive())->toBeTrue()
        ->and($running->clockInAt)->toEqual(corrTime('09:00'))
        ->and($running->regularMinutes)->toBe(150);

    $ended = corrShift('09:00', [...$events, corrEvent('clock_out', '13:00')], now: '13:30');

    expect($ended->clockInAt)->toEqual(corrTime('08:00'))
        ->and($ended->clockOutAt)->toEqual(corrTime('12:00'))
        ->and($ended->regularMinutes)->toBe(240);
});

it('uses the latest correction of a field', function () {
    $result = corrShift('09:00', [
        corrEvent('clock_out', '12:00'),
        corrApplied('clock_out_at', '15:00', '2026-09-15 09:00'),
        corrApplied('clock_out_at', '14:00', '2026-09-15 10:00'),
    ], now: '2026-09-15 11:00');

    expect($result->clockOutAt)->toEqual(corrTime('14:00'))
        ->and($result->regularMinutes)->toBe(300);
});

it('keeps an undo within 2 minutes of the recorded clock-in', function () {
    $result = corrShift('09:00', [corrEvent('clock_in_cancelled', '09:01'), corrApplied('clock_in_at', '08:00', '09:00:30')], now: '09:05');

    expect($result->cancelled)->toBeTrue();
});
