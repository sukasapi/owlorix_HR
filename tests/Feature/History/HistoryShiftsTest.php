<?php

use App\Modules\Attendance\Services\HistoryShifts;
use App\Modules\Attendance\Services\ResolvedShift;
use App\Modules\Attendance\Services\ShiftStateResolver;
use App\Modules\Calendar\Services\WorkdayResolver;
use App\Modules\Identity\Access\Role;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Tests\Feature\Attendance\Support\Desk;

// HistoryShifts reads a whole month at once for Riwayat. It must give exactly what ShiftStateResolver gives date by
// date, or Riwayat would disagree with Hari ini and the desktop app.

it('resolves a month of shifts exactly as ShiftStateResolver does, date by date', function () {
    $person = userWithRole(Role::Employee);
    $desk = Desk::for($this, $person);
    $laptop = Desk::for($this, $person, 'PC-ANIM-11');

    // Monday: two shifts on one date; the second reaches the limit, goes into overtime, and the PC shuts down
    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->send('clock_out', at: '12:00');
    $desk->send('clock_in', at: '13:00');
    $desk->heartbeat('17:58');
    $desk->send('overtime_start', ['reason' => 'Render final shot 12'], '18:03');
    $desk->heartbeat('18:58');
    $desk->send('pc_shutdown', at: '19:00');

    // Tuesday: the 8-hour prompt is never answered
    $desk->send('clock_in', at: '2026-09-15 09:00');
    $desk->heartbeat('17:30');

    // Wednesday night into Thursday, then a short Thursday with a quiet period, a move, and an undone clock-in
    $desk->send('clock_in', at: '2026-09-16 22:00');
    $desk->send('clock_out', at: '2026-09-17 02:00');
    $desk->send('clock_in', at: '2026-09-17 09:00');
    $desk->at('10:20');
    $desk->sync([$desk->event('idle_start', at: '10:05')])->assertOk();
    $desk->send('idle_end', at: '10:40');
    $desk->send('idle_tag', ['tag' => 'meeting'], '10:41');
    $laptop->send('shift_moved', at: '11:00');
    $laptop->send('clock_out', at: '15:00');
    $desk->send('clock_in', at: '16:00');
    $desk->send('clock_in_cancelled', at: '16:01');

    // Saturday, not a workday
    $desk->send('clock_in', ['reason' => 'Revisi klien untuk Senin'], '2026-09-19 10:00');
    $desk->send('clock_out', ['work_report' => 'Revisi selesai'], '12:00');

    // Wednesday 30th: still running when the page is read
    $desk->send('clock_in', at: '2026-09-30 20:00');
    $desk->heartbeat('21:58');

    $now = Desk::time('2026-09-30 22:00');
    $this->travelTo($now);

    $from = '2026-09-01';
    $to = '2026-09-30';
    $verdicts = app(WorkdayResolver::class)->range($person, $from, $to);
    $batch = app(HistoryShifts::class)->forRange($person, $from, $to, $now, $verdicts);
    $resolver = app(ShiftStateResolver::class);
    $asArrays = fn (array $shifts) => array_map(fn (ResolvedShift $r) => $r->toArray(), $shifts);

    $compared = 0;

    foreach (CarbonPeriod::create($from, $to) as $date) {
        $key = $date->toDateString();
        $expected = $asArrays($resolver->workDate($person->id, $key, CarbonImmutable::instance($now)));

        expect($asArrays($batch->shifts[$key] ?? []))->toEqual($expected, "work date {$key}");
        $compared += count($expected);
    }

    // Monday 2, Tuesday, Wednesday, Thursday (the undone clock-in is gone), Saturday, and the running shift
    expect($compared)->toBe(7);
});
