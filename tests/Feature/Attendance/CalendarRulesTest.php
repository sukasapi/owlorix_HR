<?php

use App\Modules\Calendar\Enums\CalendarDayType;
use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Calendar\Events\CalendarDatesChanged;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Identity\Access\Role;
use App\Modules\Organization\Models\Team;
use App\Modules\Overtime\Models\OvertimeRequest;
use Tests\Feature\Attendance\Support\Desk;

// 2026-09-16 is a Wednesday, 2026-09-19 a Saturday. Times are Asia/Jakarta.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->admin = userWithRole(Role::Superadmin);
    $this->lead = userWithRole(Role::TeamLead);
    $this->desk = Desk::for($this, $this->person);
});

test('3.2.1 a holiday set by Superadmin on a weekday makes the whole shift overtime', function () {
    CalendarDay::query()->create(['date' => '2026-09-16', 'type' => CalendarDayType::Holiday, 'name' => 'Libur nasional', 'created_by' => $this->admin->id]);

    $this->desk->send('clock_in', ['reason' => 'Render untuk tayang besok'], '2026-09-16 09:00');
    $this->desk->send('clock_out', ['work_report' => 'Render episode 3 selesai'], '13:00')
        ->assertJsonPath('shift', null);

    $shift = $this->desk->shift();

    expect($shift->is_workday)->toBeFalse()
        ->and($shift->regular_minutes)->toBe(0)
        ->and($shift->overtime_minutes)->toBe(240)
        ->and($shift->regular_ends_at)->toBeNull();
});

test('3.2.2 on a Saturday Management opened for the team the normal 8-hour rules apply', function () {
    $team = Team::factory()->create(['lead_user_id' => $this->lead->id]);
    $team->members()->attach($this->person);
    OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::Team, 'scope_id' => $team->id, 'opened_by' => $this->lead->id]);

    $this->desk->send('clock_in', at: '2026-09-19 09:00')
        ->assertJsonPath('is_workday', true)
        ->assertJsonPath('shift.status', 'open')
        ->assertJsonPath('shift.regular_ends_at', '2026-09-19T10:00:00.000Z')
        ->assertJsonPath('shift.overtime', null);
});

test('3.2.3 marking a worked date as a holiday recalculates the shift as overtime', function () {
    $this->desk->send('clock_in', at: '2026-09-16 09:00');
    $this->desk->send('clock_out', at: '15:00');

    expect($this->desk->shift()->regular_minutes)->toBe(360);

    CalendarDay::query()->create(['date' => '2026-09-16', 'type' => CalendarDayType::Holiday, 'name' => 'Libur susulan', 'created_by' => $this->admin->id]);
    CalendarDatesChanged::dispatch(['2026-09-16']);

    $shift = $this->desk->shift();

    expect($shift->is_workday)->toBeFalse()
        ->and($shift->regular_minutes)->toBe(0)
        ->and($shift->overtime_minutes)->toBe(360)
        ->and($shift->status->value)->toBe('report_due')
        ->and(OvertimeRequest::query()->where('shift_id', $shift->id)->value('status')->value)->toBe('pending');
});

test('3.2.3 opening a worked Saturday afterwards turns its overtime into regular time', function () {
    $this->desk->send('clock_in', ['reason' => 'Revisi klien untuk Senin'], '2026-09-19 09:00');
    $this->desk->send('clock_out', at: '12:00');

    expect(OvertimeRequest::query()->count())->toBe(1);

    OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::User, 'scope_id' => $this->person->id, 'opened_by' => $this->lead->id]);
    CalendarDatesChanged::dispatch(['2026-09-19'], [$this->person->id]);

    $shift = $this->desk->shift();

    expect($shift->is_workday)->toBeTrue()
        ->and($shift->regular_minutes)->toBe(180)
        ->and($shift->overtime_minutes)->toBe(0)
        ->and($shift->is_short)->toBeTrue()
        ->and(OvertimeRequest::query()->count())->toBe(0);
});

test('3.2.4 the app gets the calendar for the coming months to work offline', function () {
    OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::User, 'scope_id' => $this->person->id, 'opened_by' => $this->lead->id]);
    $this->desk->at('2026-09-14 08:00');

    $calendar = collect($this->desk->request('GET', '/api/v1/config')->assertOk()->json('calendar'))->keyBy('date');

    expect($calendar->keys()->first())->toBe('2026-09-14')
        ->and($calendar->keys()->last())->toBe('2026-12-14')
        ->and($calendar['2026-09-19']['is_workday'])->toBeTrue()
        ->and($calendar['2026-09-20']['is_workday'])->toBeFalse();
});
