<?php

use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Services\TodaySummary;
use App\Modules\Identity\Access\Role;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Feature\Attendance\Support\Desk;

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->desk = Desk::for($this, $this->person);
});

it('schedules attendance:settle every five minutes', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'attendance:settle'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *');
});

it('saves the state the resolver already showed, the same whenever cron runs', function (string $cronAt) {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('17:20');

    expect($this->desk->shift()->status)->toBe(ShiftStatus::Open);

    $this->desk->at($cronAt);
    $this->artisan('attendance:settle')->assertSuccessful();

    expect($this->desk->shift())
        ->status->toBe(ShiftStatus::Closed)
        ->regular_minutes->toBe(480)
        ->and($this->desk->shift()->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T10:00:00Z');
})->with(['17:31', '17:35', '2026-09-15 03:00']);

it('leaves a shift that is still running open', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('11:00');

    $this->artisan('attendance:settle')->assertSuccessful();

    expect($this->desk->shift())->status->toBe(ShiftStatus::Open)->regular_minutes->toBe(120);
});

it('summarises today for the web Hari ini page', function () {
    $this->desk->send('clock_in', at: '2026-09-14 08:00');
    $this->desk->at('10:10');
    $this->desk->sync([$this->desk->event('idle_start', at: '10:00')]);
    $this->desk->send('idle_end', at: '10:30');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '16:05');
    $this->desk->heartbeat('17:00');

    $today = app(TodaySummary::class)->for($this->person);

    expect($today)
        ->date->toBe('2026-09-14')
        ->is_workday->toBeTrue()
        ->status->toBe('overtime')
        ->regular_minutes->toBe(480)
        ->overtime_minutes->toBe(60)
        ->idle_minutes->toBe(30)
        ->regular_ends_at->toBe('2026-09-14T09:00:00.000Z')
        ->and($today['idle_periods'][0]['started_at'])->toBe('2026-09-14T03:00:00.000Z')
        ->and($today['shifts'])->toHaveCount(1)
        ->and($today['shifts'][0]['overtime']['status'])->toBe('pending')
        ->and($today['shifts'][0]['report_due'])->toBeFalse()
        ->and($today['shifts'][0]['late_claim'])->toBeNull();
});

it('includes a shift from yesterday that is still running after midnight', function () {
    $this->desk->send('clock_in', at: '2026-09-14 22:00');
    $this->desk->heartbeat('2026-09-15 01:00');

    $today = app(TodaySummary::class)->for($this->person);

    expect($today)
        ->date->toBe('2026-09-15')
        ->status->toBe('open')
        // The running shift's own date total, not today's 0 (docs/13 section 6)
        ->regular_minutes->toBe(180)
        ->and($today['shifts'][0]['work_date'])->toBe('2026-09-14')
        ->and($today['shifts'][0]['regular_minutes'])->toBe(180);
});

it('shows signed out with no shifts', function () {
    $this->desk->at('2026-09-14 09:00');

    expect(app(TodaySummary::class)->for($this->person))
        ->status->toBe('signed_out')
        ->shifts->toBe([])
        ->regular_ends_at->toBeNull();
});
