<?php

use App\Modules\Attendance\Enums\EndReason;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Calendar\Enums\CalendarDayType;
use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Calendar\Events\CalendarDatesChanged;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Identity\Access\Role;
use App\Modules\Organization\Models\Team;
use App\Modules\Overtime\Actions\DecideOvertime;
use App\Modules\Overtime\Enums\Decision;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Overtime\Services\OvertimeApprovers;
use Tests\Feature\Attendance\Support\Desk;

// Section 4 of docs/02-attendance-rules.md. Monday 2026-09-14, Saturday 2026-09-19, Asia/Jakarta.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->lead = userWithRole(Role::TeamLead);
    $this->admin = userWithRole(Role::Superadmin);
    $this->desk = Desk::for($this, $this->person);
});

test('4 forgets to clock out with the PC left on: prompt, no answer, clocked out at the 8-hour mark', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('17:00');
    $this->desk->heartbeat('20:00')->assertJsonPath('shift', null);

    $this->artisan('attendance:settle');

    expect($this->desk->shift())
        ->status->toBe(ShiftStatus::Closed)
        ->end_reason->toBe(EndReason::AutoNoAnswer)
        ->regular_minutes->toBe(480)
        ->overtime_minutes->toBe(0);
});

test('4 long render overnight with overtime chosen and tagged Render keeps overtime running', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render sequence 4 semalam'], '17:01');
    $this->desk->send('idle_tag', ['tag' => 'rendering', 'pre_tag' => true], '18:00');
    $this->desk->at('18:15');
    $this->desk->sync([$this->desk->event('idle_start', at: '18:05')])->assertOk();

    $this->desk->heartbeat('2026-09-15 02:00')
        ->assertJsonPath('shift.status', 'overtime')
        ->assertJsonPath('shift.work_date', '2026-09-14')
        ->assertJsonPath('shift.overtime.minutes', 540);
});

test('4 long render overnight without a tag: the presence check ends overtime at the start of the quiet period', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render sequence 4 semalam'], '17:01');
    $this->desk->at('18:15');
    $this->desk->sync([$this->desk->event('idle_start', at: '18:05')])->assertOk();

    $this->desk->heartbeat('2026-09-15 02:00')->assertJsonPath('shift', null);

    expect($this->desk->state()['reports_due'][0]['overtime_ended_at'])->toBe('2026-09-14T11:05:00.000Z');
});

test('4 studio 09.00 to 14.00 then 19.00 to 23.00 on the same date: 5 h, then 3 h more and the prompt at 22.00', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('clock_out', at: '14:00');

    $home = Desk::for($this, $this->person, 'PC-HOME-01');
    $home->send('clock_in', at: '19:00')->assertJsonPath('shift.regular_ends_at', '2026-09-14T15:00:00.000Z');
    $home->heartbeat('22:00')
        ->assertJsonPath('shift.status', 'prompted')
        ->assertJsonPath('regular_minutes', 480);

    $shifts = Shift::query()->orderBy('clock_in_at')->get();

    expect($shifts[0]->regular_minutes)->toBe(300)
        ->and($shifts[1]->regular_before_minutes)->toBe(300);
});

test('4 clocks out at 17.00 after 8 h and signs in again at 19.00: prompt right away, continuing is overtime', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('clock_out', at: '17:00');

    $this->desk->send('clock_in', at: '19:00')->assertJsonPath('shift.status', 'prompted');
    $this->desk->send('overtime_start', ['reason' => 'Revisi lighting mendesak'], '19:01');
    $this->desk->send('clock_out', ['work_report' => 'Lighting shot 2 selesai'], '21:00');

    $request = OvertimeRequest::query()->sole();

    expect($request->minutes)->toBe(120)
        ->and($request->started_at->toIso8601ZuluString())->toBe('2026-09-14T12:00:00Z');
});

test('4 works on a Saturday nobody opened: the whole shift is overtime and the reason is asked at sign-in', function () {
    $this->desk->send('clock_in', ['reason' => 'Kejar deadline klien'], '2026-09-19 10:00')
        ->assertJsonPath('is_workday', false)
        ->assertJsonPath('shift.overtime.reason', 'Kejar deadline klien');
    $this->desk->send('clock_out', ['work_report' => 'Animasi shot 9'], '14:00');

    expect($this->desk->shift())->overtime_minutes->toBe(240)->regular_minutes->toBe(0);
});

test('4 works on a Saturday Management opened for the team: normal 8-hour rules', function () {
    $team = Team::factory()->create(['lead_user_id' => $this->lead->id]);
    $team->members()->attach($this->person);
    OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::Team, 'scope_id' => $team->id, 'opened_by' => $this->lead->id]);

    $this->desk->send('clock_in', at: '2026-09-19 09:00');
    $this->desk->send('clock_out', at: '15:00');

    expect($this->desk->shift())->regular_minutes->toBe(360)->overtime_minutes->toBe(0)->is_short->toBeTrue();
});

test('4 works on a public holiday: whole shift is overtime, unless Management opened the date', function () {
    CalendarDay::query()->create(['date' => '2026-09-16', 'type' => CalendarDayType::Holiday, 'name' => 'Libur nasional', 'created_by' => $this->admin->id]);
    $colleague = userWithRole(Role::Employee);
    OpenedWorkday::query()->create(['date' => '2026-09-16', 'scope_type' => OpenedScope::User, 'scope_id' => $colleague->id, 'opened_by' => $this->lead->id]);

    $this->desk->send('clock_in', ['reason' => 'Render untuk tayang'], '2026-09-16 09:00')->assertJsonPath('shift.status', 'overtime');
    Desk::for($this, $colleague, 'PC-ANIM-08')->send('clock_in', at: '09:00')->assertJsonPath('shift.status', 'open');
});

test('4 Superadmin marks a date as holiday after people worked it: shifts recalculated as overtime', function () {
    $this->desk->send('clock_in', at: '2026-09-17 09:00');
    $this->desk->send('clock_out', at: '12:00');

    CalendarDay::query()->create(['date' => '2026-09-17', 'type' => CalendarDayType::StudioDayOff, 'name' => 'Libur studio', 'created_by' => $this->admin->id]);
    CalendarDatesChanged::dispatch(['2026-09-17']);

    expect($this->desk->shift())->overtime_minutes->toBe(180)->regular_minutes->toBe(0)->is_workday->toBeFalse();
});

test('4 power cut at 14:00, back at 14:40: the shift resumes with a 40-minute interruption', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('14:00');

    expect($this->desk->state('14:30')['shift']['status'])->toBe('interrupted');

    $this->desk->send('shift_resumed', at: '14:40')
        ->assertJsonPath('shift.status', 'open')
        ->assertJsonPath('shift.interruption_minutes', 40)
        ->assertJsonPath('shift.interruptions.0.started_at', '2026-09-14T07:00:00.000Z')
        ->assertJsonPath('shift.interruptions.0.ended_at', '2026-09-14T07:40:00.000Z');
});

test('4 signs in on PC-A and moves to PC-B for a review session: the shift moves', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $pcB = Desk::for($this, $this->person, 'PC-REVIEW-01');

    $pcB->send('shift_moved', at: '13:00')->assertJsonPath('shift.device_id', $pcB->device->id);

    expect(Shift::query()->count())->toBe(1);
});

test('4 internet down all day: everything is kept locally and syncs when back', function () {
    // Online at sign-in, then the connection drops; heartbeats after 09:10 never reach the server
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('09:10');

    // The server only sees an old heartbeat and closes the shift for review when asked
    expect($this->desk->state('13:00')['shift'])->toBeNull();

    $this->desk->at('18:30');
    $this->desk->sync([
        $this->desk->event('idle_start', at: '12:00', overrides: ['offline' => true]),
        $this->desk->event('idle_end', at: '12:30', overrides: ['offline' => true]),
        $this->desk->event('idle_tag', ['tag' => 'break'], '12:31', ['offline' => true]),
        // The app crashed at about 13:00 and was restarted; its last event before that was the tag at 12:31
        $this->desk->event('shift_resumed', at: '13:01', overrides: ['offline' => true]),
        $this->desk->event('regular_time_reached', at: '17:30', overrides: ['offline' => true]),
        $this->desk->event('clock_out', at: '17:35', overrides: ['offline' => true]),
        $this->desk->event('heartbeat', at: '17:35', overrides: ['offline' => true]),
    ])->assertOk();

    expect($this->desk->shift())
        ->status->toBe(ShiftStatus::Closed)
        ->interruption_minutes->toBe(30)
        ->regular_minutes->toBe(480)
        ->idle_minutes->toBe(30)
        ->flags->toBe([])
        ->and($this->desk->shift()->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T10:35:00Z');
});

test('4 changes the Windows clock to look earlier: detected against server time and flagged', function () {
    // The clock was set 30 minutes back and the app still sends its old offset of 0
    $this->desk->at('2026-09-14 09:30');
    $this->desk->sync([$this->desk->event('clock_in', at: '09:00')])
        ->assertJsonPath('shift.clock_in_at', '2026-09-14T02:30:00.000Z')
        ->assertJsonPath('shift.flags', ['clock_mismatch']);
});

test('4 a shift that crosses midnight belongs to the start date', function () {
    $this->desk->send('clock_in', at: '2026-09-14 22:00');
    $this->desk->send('clock_out', at: '2026-09-15 02:00');

    expect($this->desk->shift())->work_date->toBe('2026-09-14')->regular_minutes->toBe(240);
});

test('4 overtime rejected: minutes stay in the record and the person sees the note', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:01');
    $this->desk->send('clock_out', ['work_report' => 'Render selesai'], '19:00');
    $request = OvertimeRequest::query()->sole();

    $director = userWithRole(Role::ProjectDirector);
    app(DecideOvertime::class)($director, $request, Decision::Rejected, 'Render bisa besok pagi');
    $this->artisan('attendance:settle');

    expect($request->refresh())
        ->status->toBe(OvertimeStatus::Rejected)
        ->minutes->toBe(120)
        ->and($request->latestDecision->note)->toBe('Render bisa besok pagi')
        ->and($this->desk->shift()->overtime_minutes)->toBe(120);
});

test('4 the person is the only Team Lead of their team: the request goes to Project Managers and Project Directors', function () {
    $team = Team::factory()->create(['lead_user_id' => $this->lead->id]);
    $team->members()->attach($this->lead);
    $manager = userWithRole(Role::ProjectManager);
    $director = userWithRole(Role::ProjectDirector);

    $leadDesk = Desk::for($this, $this->lead, 'PC-LEAD-01');
    $leadDesk->send('clock_in', ['reason' => 'Review hasil render tim'], '2026-09-19 10:00');

    expect(app(OvertimeApprovers::class)->for(OvertimeRequest::query()->sole())->pluck('id')->sort()->values()->all())
        ->toBe(collect([$manager->id, $director->id])->sort()->values()->all());
});

test('4 app killed through Task Manager: heartbeats stop and it is handled like a crash', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('11:00');

    expect($this->desk->state('11:30')['shift']['status'])->toBe('interrupted')
        ->and($this->desk->state('12:31')['shift'])->toBeNull();

    $this->artisan('attendance:settle');

    expect($this->desk->shift())
        ->status->toBe(ShiftStatus::NeedsReview)
        ->regular_minutes->toBe(120)
        ->and($this->desk->shift()->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T04:00:00Z');
});
