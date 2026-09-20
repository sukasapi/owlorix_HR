<?php

use App\Modules\Attendance\Enums\IdleTag;
use App\Modules\Attendance\Models\IdlePeriod;
use App\Modules\Attendance\Services\TodaySummary;
use App\Modules\Identity\Access\Role;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Attendance\Support\Desk;

// Monday 2026-09-14, Asia/Jakarta.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->desk = Desk::for($this, $this->person);
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
});

test('3.6.1 an idle period keeps only its times and an optional tag, nothing about input', function () {
    $this->desk->at('10:12');
    $this->desk->sync([$this->desk->event('idle_start', ['keys' => 'should not be kept'], '10:00')])->assertOk();
    $this->desk->send('idle_end', at: '10:30');

    expect(Schema::getColumnListing('idle_periods'))->toEqualCanonicalizing(['id', 'shift_id', 'started_at', 'ended_at', 'minutes', 'tag', 'note'])
        ->and(IdlePeriod::query()->sole()->only(['minutes', 'tag', 'note']))->toBe(['minutes' => 30, 'tag' => null, 'note' => null]);
});

test('3.6.2 idle_start is backdated to the last input', function () {
    $this->desk->at('10:10');
    $this->desk->sync([$this->desk->event('idle_start', at: '10:00')])
        ->assertJsonPath('shift.idle_periods.0.started_at', '2026-09-14T03:00:00.000Z')
        ->assertJsonPath('shift.idle_minutes', 10);
});

test('3.6.3 the next input ends the period and the optional tag is kept with its note', function () {
    $this->desk->at('11:15');
    $this->desk->sync([$this->desk->event('idle_start', at: '11:00')])->assertOk();
    $this->desk->send('idle_end', at: '12:00');
    $this->desk->send('idle_tag', ['tag' => 'other', 'note' => 'Diskusi storyboard'], '12:01');

    $period = IdlePeriod::query()->sole();

    expect($period->tag)->toBe(IdleTag::Other)
        ->and($period->note)->toBe('Diskusi storyboard')
        ->and($period->ended_at->toIso8601ZuluString())->toBe('2026-09-14T05:00:00Z');
});

test('3.6.4 idle periods are shown on My Day', function () {
    $this->desk->at('11:10');
    $this->desk->sync([$this->desk->event('idle_start', at: '11:00')])->assertOk();
    $this->desk->send('idle_end', at: '11:40');
    $this->desk->send('idle_tag', ['tag' => 'meeting'], '11:41');

    $today = app(TodaySummary::class)->for($this->person);

    expect($today['idle_periods'])->toHaveCount(1)
        ->and($today['idle_periods'][0]['tag'])->toBe('meeting')
        ->and($today['idle_minutes'])->toBe(40);
});

test('3.6.5 idle time is not subtracted from regular or overtime minutes', function () {
    $this->desk->at('12:10');
    $this->desk->sync([$this->desk->event('idle_start', at: '12:00')])->assertOk();
    $this->desk->send('idle_end', at: '13:00');
    $this->desk->send('clock_out', at: '15:00');

    $shift = $this->desk->shift();

    expect($shift->regular_minutes)->toBe(360)
        ->and($shift->idle_minutes)->toBe(60);
});

test('3.6.6 the 8-hour prompt still appears during idle and the 30-minute no-answer rule applies', function () {
    $this->desk->at('16:40');
    $this->desk->sync([$this->desk->event('idle_start', at: '16:30')])->assertOk();

    $this->desk->heartbeat('17:10')->assertJsonPath('shift.status', 'prompted');
    $this->desk->heartbeat('17:31')->assertJsonPath('shift', null);

    $this->artisan('attendance:settle');

    expect($this->desk->shift()->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T10:00:00Z')
        ->and($this->desk->shift()->idle_minutes)->toBe(30);
});
