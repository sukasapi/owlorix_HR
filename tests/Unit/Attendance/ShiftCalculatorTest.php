<?php

use App\Modules\Attendance\Calculation\ShiftCalculator;
use App\Modules\Attendance\Calculation\ShiftEvent;
use App\Modules\Attendance\Calculation\ShiftInput;
use App\Modules\Attendance\Calculation\ShiftResult;
use App\Modules\Attendance\Enums\EndReason;
use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Enums\IdleTag;
use App\Modules\Attendance\Enums\OvertimeEndReason;
use App\Modules\Attendance\Enums\ShiftFlag;
use App\Modules\Attendance\Enums\ShiftStatus;
use Carbon\CarbonImmutable;

// Times are written in Asia/Jakarta on Monday 2026-09-14 unless a date is given.

function calcTime(string $time): CarbonImmutable
{
    $value = str_contains($time, '-') ? $time : "2026-09-14 {$time}";

    return CarbonImmutable::parse($value, 'Asia/Jakarta')->utc();
}

/** @param array<string, mixed> $payload */
function calcEvent(string $type, string $at, array $payload = [], string $device = 'PC-A', ?string $deviceAt = null, bool $offline = false): ShiftEvent
{
    static $sequence = 0;
    $sequence++;

    return new ShiftEvent(
        id: sprintf('event-%05d', $sequence),
        type: EventType::from($type),
        occurredAt: calcTime($at),
        occurredAtDevice: calcTime($deviceAt ?? $at),
        deviceId: $device,
        payload: $payload,
        offline: $offline,
    );
}

/**
 * @param  list<ShiftEvent>  $events
 * @param  array{workday?: bool, before?: int, last_seen?: string, next?: string, clock_in_payload?: array<string, mixed>, offline?: bool, clock_in_device_at?: string}  $options
 */
function calcShift(string $clockIn, array $events, string $now, array $options = []): ShiftResult
{
    return (new ShiftCalculator)->calculate(new ShiftInput(
        clockIn: calcEvent('clock_in', $clockIn, $options['clock_in_payload'] ?? [], deviceAt: $options['clock_in_device_at'] ?? null, offline: $options['offline'] ?? false),
        events: $events,
        isWorkday: $options['workday'] ?? true,
        regularLimitMinutes: 480,
        regularBeforeMinutes: $options['before'] ?? 0,
        lastSeenAt: calcTime($options['last_seen'] ?? $now),
        now: calcTime($now),
        nextClockInAt: isset($options['next']) ? calcTime($options['next']) : null,
    ));
}

it('closes a shift at the clock-out before the mark and counts regular time', function () {
    $result = calcShift('09:00', [calcEvent('clock_out', '14:00')], '14:05');

    expect($result->status)->toBe(ShiftStatus::Closed)
        ->and($result->endReason)->toBe(EndReason::Manual)
        ->and($result->regularMinutes)->toBe(300)
        ->and($result->overtimeMinutes)->toBe(0)
        ->and($result->regularEndsAt)->toEqual(calcTime('17:00'))
        ->and($result->overtime)->toBeNull();
});

it('keeps an open shift open and counts up to now', function () {
    $result = calcShift('09:00', [], '11:30');

    expect($result->status)->toBe(ShiftStatus::Open)
        ->and($result->isLive())->toBeTrue()
        ->and($result->regularMinutes)->toBe(150)
        ->and($result->regularEndsAt)->toEqual(calcTime('17:00'));
});

it('prompts at the mark and waits 30 minutes for an answer', function () {
    $result = calcShift('09:00', [], '17:20');

    expect($result->status)->toBe(ShiftStatus::Prompted)
        ->and($result->regularMinutes)->toBe(480)
        ->and($result->promptDeadlineAt)->toEqual(calcTime('17:30'));
});

it('closes at the mark when the prompt is not answered, whenever it is computed', function (string $now) {
    $result = calcShift('09:00', [], $now);

    expect($result->status)->toBe(ShiftStatus::Closed)
        ->and($result->endReason)->toBe(EndReason::AutoNoAnswer)
        ->and($result->clockOutAt)->toEqual(calcTime('17:00'))
        ->and($result->regularMinutes)->toBe(480)
        ->and($result->overtimeMinutes)->toBe(0);
})->with(['17:31', '23:00']);

it('treats a clock-out in the prompt as the end without overtime', function () {
    $result = calcShift('09:00', [calcEvent('clock_out', '17:12')], '17:40');

    expect($result->clockOutAt)->toEqual(calcTime('17:12'))
        ->and($result->regularMinutes)->toBe(480)
        ->and($result->overtimeMinutes)->toBe(0)
        ->and($result->status)->toBe(ShiftStatus::Closed);
});

it('starts overtime at the mark when keep working is chosen', function () {
    $result = calcShift('09:00', [
        calcEvent('overtime_start', '17:05', ['reason' => 'Render final shot 12']),
        calcEvent('clock_out', '19:00'),
    ], '19:10');

    expect($result->status)->toBe(ShiftStatus::ReportDue)
        ->and($result->overtime->startedAt)->toEqual(calcTime('17:00'))
        ->and($result->overtime->endedAt)->toEqual(calcTime('19:00'))
        ->and($result->overtime->reason)->toBe('Render final shot 12')
        ->and($result->overtimeMinutes)->toBe(120)
        ->and($result->overtimeEndReason)->toBe(OvertimeEndReason::ClockOut);
});

it('closes an overtime shift once the work report is written', function () {
    $result = calcShift('09:00', [
        calcEvent('overtime_start', '17:05', ['reason' => 'Render final shot 12']),
        calcEvent('clock_out', '19:00'),
        calcEvent('overtime_report', '19:01', ['work_report' => 'Rendered shot 12']),
    ], '19:10');

    expect($result->status)->toBe(ShiftStatus::Closed)
        ->and($result->overtime->workReport)->toBe('Rendered shot 12')
        ->and($result->overtime->reportSubmittedAt)->toEqual(calcTime('19:01'));
});

it('counts a whole non-workday shift as overtime', function () {
    $result = calcShift('10:00', [calcEvent('clock_out', '14:00')], '14:00', ['workday' => false, 'clock_in_payload' => ['reason' => 'Deadline klien Sabtu']]);

    expect($result->regularMinutes)->toBe(0)
        ->and($result->overtimeMinutes)->toBe(240)
        ->and($result->regularEndsAt)->toBeNull()
        ->and($result->overtime->startedAt)->toEqual(calcTime('10:00'))
        ->and($result->overtime->reason)->toBe('Deadline klien Sabtu')
        ->and($result->status)->toBe(ShiftStatus::ReportDue);
});

it('uses the regular time left on the work date', function () {
    $result = calcShift('19:00', [], '22:10', ['before' => 300]);

    expect($result->regularEndsAt)->toEqual(calcTime('22:00'))
        ->and($result->status)->toBe(ShiftStatus::Prompted)
        ->and($result->regularMinutes)->toBe(180);
});

it('prompts at clock-in when the limit was already reached', function () {
    $result = calcShift('19:00', [calcEvent('overtime_start', '19:01', ['reason' => 'Lanjut revisi animasi'])], '20:00', ['before' => 480]);

    expect($result->regularEndsAt)->toBeNull()
        ->and($result->status)->toBe(ShiftStatus::Overtime)
        ->and($result->regularMinutes)->toBe(0)
        ->and($result->overtime->startedAt)->toEqual(calcTime('19:00'))
        ->and($result->overtimeMinutes)->toBe(60);
});

it('pushes the mark by an interruption the person came back from', function () {
    $result = calcShift('09:00', [
        calcEvent('pc_shutdown', '14:00'),
        calcEvent('shift_resumed', '14:40'),
    ], '15:00');

    expect($result->status)->toBe(ShiftStatus::Open)
        ->and($result->interruptionMinutes)->toBe(40)
        ->and($result->regularMinutes)->toBe(320)
        ->and($result->regularEndsAt)->toEqual(calcTime('17:40'));
});

it('closes for review when nobody comes back within 90 minutes', function () {
    $result = calcShift('09:00', [calcEvent('pc_shutdown', '14:00')], '15:31', ['last_seen' => '14:00']);

    expect($result->status)->toBe(ShiftStatus::NeedsReview)
        ->and($result->endReason)->toBe(EndReason::ShutdownTimeout)
        ->and($result->clockOutAt)->toEqual(calcTime('14:00'))
        ->and($result->regularMinutes)->toBe(300);
});

it('shows the shift as interrupted inside the resume window', function () {
    $result = calcShift('09:00', [calcEvent('pc_sleep', '14:00')], '15:00', ['last_seen' => '14:00']);

    expect($result->status)->toBe(ShiftStatus::Interrupted)
        ->and($result->isLive())->toBeTrue()
        ->and($result->regularMinutes)->toBe(300);
});

it('treats missing heartbeats as a crash', function () {
    $interrupted = calcShift('09:00', [], '13:00', ['last_seen' => '12:00']);
    $closed = calcShift('09:00', [], '13:31', ['last_seen' => '12:00']);

    expect($interrupted->status)->toBe(ShiftStatus::Interrupted)
        ->and($closed->status)->toBe(ShiftStatus::NeedsReview)
        ->and($closed->clockOutAt)->toEqual(calcTime('12:00'));
});

it('records a crash gap when the shift resumes', function () {
    $result = calcShift('09:00', [
        calcEvent('shift_resumed', '12:30', ['_server' => ['last_seen_at' => calcTime('12:00')->toIso8601ZuluString()]]),
    ], '13:00');

    expect($result->interruptionMinutes)->toBe(30)
        ->and($result->regularMinutes)->toBe(210)
        ->and($result->status)->toBe(ShiftStatus::Open);
});

it('ends overtime at the start of an unanswered quiet period', function () {
    $result = calcShift('09:00', [
        calcEvent('overtime_start', '17:02', ['reason' => 'Render final shot 12']),
        calcEvent('idle_start', '18:00'),
    ], '19:31');

    expect($result->status)->toBe(ShiftStatus::ReportDue)
        ->and($result->overtimeEndReason)->toBe(OvertimeEndReason::PresenceCheckNoAnswer)
        ->and($result->clockOutAt)->toEqual(calcTime('18:00'))
        ->and($result->overtimeMinutes)->toBe(60);
});

it('keeps overtime running while the presence check waits for an answer', function () {
    $result = calcShift('09:00', [
        calcEvent('overtime_start', '17:02', ['reason' => 'Render final shot 12']),
        calcEvent('idle_start', '18:00'),
    ], '19:29');

    expect($result->status)->toBe(ShiftStatus::Overtime);
});

it('keeps overtime when the presence check is answered', function () {
    $result = calcShift('09:00', [
        calcEvent('overtime_start', '17:02', ['reason' => 'Render final shot 12']),
        calcEvent('idle_start', '18:00'),
        calcEvent('idle_end', '19:10'),
        calcEvent('presence_confirmed', '19:11'),
    ], '20:00');

    expect($result->status)->toBe(ShiftStatus::Overtime)
        ->and($result->idleMinutes)->toBe(70)
        ->and($result->overtimeMinutes)->toBe(180);
});

it('skips the presence check for a quiet period tagged Render in advance', function () {
    $result = calcShift('09:00', [
        calcEvent('overtime_start', '17:02', ['reason' => 'Render final shot 12']),
        calcEvent('idle_tag', '17:59', ['tag' => 'rendering', 'pre_tag' => true]),
        calcEvent('idle_start', '18:00'),
    ], '23:00');

    expect($result->status)->toBe(ShiftStatus::Overtime)
        ->and($result->idlePeriods[0]->tag)->toBe(IdleTag::Rendering)
        ->and($result->overtimeMinutes)->toBe(360);
});

it('never subtracts idle time', function () {
    $result = calcShift('09:00', [
        calcEvent('idle_start', '12:00'),
        calcEvent('idle_end', '13:00'),
        calcEvent('idle_tag', '13:01', ['tag' => 'break']),
        calcEvent('clock_out', '15:00'),
    ], '15:00');

    expect($result->regularMinutes)->toBe(360)
        ->and($result->idleMinutes)->toBe(60)
        ->and($result->idlePeriods[0]->tag)->toBe(IdleTag::Break);
});

it('removes a clock-in undone within 2 minutes and ignores a later undo', function () {
    $cancelled = calcShift('09:00', [calcEvent('clock_in_cancelled', '09:01:30')], '09:05');
    $late = calcShift('09:00', [calcEvent('clock_in_cancelled', '09:02:01')], '09:05');

    expect($cancelled->cancelled)->toBeTrue()
        ->and($late->cancelled)->toBeFalse()
        ->and($late->status)->toBe(ShiftStatus::Open);
});

it('extends an auto-closed shift with a late claim', function () {
    $result = calcShift('09:00', [
        calcEvent('overtime_claim', '2026-09-15 08:00', ['ended_at' => calcTime('19:00')->toIso8601ZuluString(), 'reason' => 'Lupa jawab, masih render', 'work_report' => 'Render shot 4']),
    ], '2026-09-15 08:00');

    expect($result->hasFlag(ShiftFlag::LateClaim))->toBeTrue()
        ->and($result->overtime->isLateClaim)->toBeTrue()
        ->and($result->overtime->startedAt)->toEqual(calcTime('17:00'))
        ->and($result->overtimeMinutes)->toBe(120)
        ->and($result->endReason)->toBe(EndReason::AutoNoAnswer)
        ->and($result->status)->toBe(ShiftStatus::Closed);
});

it('ignores a late claim after the claim window', function () {
    $result = calcShift('09:00', [
        calcEvent('overtime_claim', '2026-09-15 17:01', ['ended_at' => calcTime('19:00')->toIso8601ZuluString(), 'reason' => 'Lupa jawab, masih render', 'work_report' => 'Render']),
    ], '2026-09-15 17:01');

    expect($result->overtime)->toBeNull();
});

it('closes a forgotten shift for review when the person clocks in again', function () {
    $result = calcShift('09:00', [calcEvent('idle_start', '10:00')], '2026-09-15 09:00', ['last_seen' => '10:30', 'next' => '2026-09-15 08:00']);

    expect($result->status)->toBe(ShiftStatus::NeedsReview)
        ->and($result->clockOutAt)->toEqual(calcTime('10:30'));
});

it('takes the device of a moved shift', function () {
    $result = calcShift('09:00', [calcEvent('shift_moved', '11:00', device: 'PC-B')], '11:01');

    expect($result->deviceId)->toBe('PC-B')
        ->and($result->interruptionMinutes)->toBe(0)
        ->and($result->regularMinutes)->toBe(121);
});

it('flags a PC clock more than 2 minutes off and an offline sign-in', function () {
    $result = calcShift('09:00', [], '10:00', ['clock_in_device_at' => '08:50', 'offline' => true]);

    expect($result->flags)->toBe([ShiftFlag::ClockMismatch, ShiftFlag::OfflineSignIn]);
});

it('gives the same result for the same input', function () {
    $events = [calcEvent('overtime_start', '17:01', ['reason' => 'Render final shot 12']), calcEvent('idle_start', '18:30')];

    expect(calcShift('09:00', $events, '21:00'))->toEqual(calcShift('09:00', array_reverse($events), '21:00'));
});

it('ends a sleep interruption at the wake of the same PC', function () {
    $result = calcShift('09:00', [calcEvent('pc_sleep', '12:00'), calcEvent('pc_wake', '12:20')], '14:01');
    $otherPc = calcShift('09:00', [calcEvent('pc_sleep', '12:00'), calcEvent('pc_wake', '12:20', device: 'PC-B')], '14:01');

    expect($result->status)->toBe(ShiftStatus::Open)
        ->and($result->interruptionMinutes)->toBe(20)
        ->and($otherPc->status)->toBe(ShiftStatus::NeedsReview);
});

it('does not end a shutdown interruption with a wake', function () {
    $result = calcShift('09:00', [calcEvent('pc_shutdown', '12:00'), calcEvent('pc_wake', '12:20')], '13:31');

    expect($result->status)->toBe(ShiftStatus::NeedsReview);
});

it('continues a shift the PC resumed after a gap longer than the resume window, flagged for review', function () {
    $result = calcShift('09:00', [
        calcEvent('shift_resumed', '12:30', ['_server' => ['last_seen_at' => calcTime('09:10')->toIso8601ZuluString()]]),
    ], '13:00');

    expect($result->status)->toBe(ShiftStatus::Open)
        ->and($result->interruptionMinutes)->toBe(200)
        ->and($result->flags)->toBe([ShiftFlag::GapUnverified]);
});

it('treats a restart within two heartbeats as no interruption', function () {
    $result = calcShift('09:00', [
        calcEvent('shift_resumed', '12:00:30', ['_server' => ['last_seen_at' => calcTime('11:58')->toIso8601ZuluString()]]),
    ], '12:01');

    expect($result->interruptionMinutes)->toBe(0);
});

it('applies a Render pre-tag that sorts just after the backdated idle start', function () {
    $result = calcShift('09:00', [
        calcEvent('overtime_start', '17:02', ['reason' => 'Render final shot 12']),
        calcEvent('idle_start', '18:00:00.300'),
        calcEvent('idle_tag', '18:00:00.400', ['tag' => 'rendering', 'pre_tag' => true]),
    ], '23:00');

    expect($result->status)->toBe(ShiftStatus::Overtime)
        ->and($result->idlePeriods[0]->tag)->toBe(IdleTag::Rendering);
});

it('bounds the late claim window by the last activity, the next clock-in and now', function () {
    $auto = calcShift('09:00', [calcEvent('pc_shutdown', '17:05')], '2026-09-15 08:00', ['last_seen' => '17:04']);
    $noActivity = calcShift('09:00', [], '2026-09-15 08:00', ['last_seen' => '16:30']);

    expect($auto->claimableUntil)->toEqual(calcTime('2026-09-15 17:00'))
        ->and($auto->latestClaimEndAt)->toEqual(calcTime('17:05'))
        ->and($noActivity->claimableUntil)->toBeNull();
});

it('caps a late claim at the last activity', function () {
    $result = calcShift('09:00', [
        calcEvent('pc_shutdown', '17:05'),
        calcEvent('overtime_claim', '2026-09-15 08:00', ['ended_at' => calcTime('2026-09-15 07:00')->toIso8601ZuluString(), 'reason' => 'Masih render', 'work_report' => 'Render']),
    ], '2026-09-15 08:00', ['last_seen' => '17:04']);

    expect($result->clockOutAt)->toEqual(calcTime('17:05'))
        ->and($result->overtimeMinutes)->toBe(5);
});

it('asks "Masih lembur?" on a timer during overtime in a browser and ends overtime when the check was shown', function () {
    $input = fn (array $events, string $now) => (new ShiftCalculator)->calculate(new ShiftInput(
        clockIn: calcEvent('clock_in', '09:00', device: 'web:abc'),
        events: $events,
        isWorkday: true,
        regularLimitMinutes: 480,
        regularBeforeMinutes: 0,
        lastSeenAt: calcTime($now),
        now: calcTime($now),
    ));

    $keepWorking = calcEvent('overtime_start', '17:05', ['reason' => 'Render final shot 12'], 'web:abc');

    $waiting = $input([$keepWorking], '18:20');
    $ended = $input([$keepWorking], '18:31');
    $answered = $input([$keepWorking, calcEvent('presence_confirmed', '18:10', device: 'web:abc')], '18:31');

    expect($waiting->status)->toBe(ShiftStatus::Overtime)
        ->and($waiting->webPresenceCheckAt)->toEqual(calcTime('18:00'))
        ->and($waiting->webPresenceAnswerBy)->toEqual(calcTime('18:30'))
        ->and($ended->status)->toBe(ShiftStatus::ReportDue)
        ->and($ended->overtimeEndReason)->toBe(OvertimeEndReason::PresenceCheckNoAnswer)
        ->and($ended->clockOutAt)->toEqual(calcTime('18:00'))
        ->and($answered->status)->toBe(ShiftStatus::Overtime)
        ->and($answered->webPresenceCheckAt)->toEqual(calcTime('19:10'));
});

it('uses the quiet-period check again once a web shift moves to a PC', function () {
    $result = (new ShiftCalculator)->calculate(new ShiftInput(
        clockIn: calcEvent('clock_in', '09:00', device: 'web:abc'),
        events: [
            calcEvent('overtime_start', '17:05', ['reason' => 'Render final shot 12'], 'web:abc'),
            calcEvent('shift_moved', '17:30', device: 'PC-A'),
        ],
        isWorkday: true,
        regularLimitMinutes: 480,
        regularBeforeMinutes: 0,
        lastSeenAt: calcTime('20:00'),
        now: calcTime('20:00'),
    ));

    expect($result->status)->toBe(ShiftStatus::Overtime)
        ->and($result->webPresenceCheckAt)->toBeNull();
});
