<?php

use App\Modules\Attendance\Services\TodaySummary;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Organization\Models\Team;
use App\Modules\Reporting\Services\MonthlyRecap;
use App\Modules\Reporting\Services\PersonRow;
use App\Modules\Reporting\Services\ReportMonth;
use Carbon\CarbonImmutable;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\Reports\Support\ReportMonthScenario;

beforeEach(function () {
    $this->lead = userWithRole(Role::TeamLead);
    $this->person = userWithRole(Role::Employee);
    $this->admin = userWithRole(Role::Superadmin);

    $this->team = Team::factory()->create(['name' => 'Animation', 'lead_user_id' => $this->lead->id]);
    $this->team->members()->attach([$this->person->id, $this->lead->id]);
});

function reportRowFor(MonthlyRecap $recap, $viewer, string $month, int $userId, string $at): PersonRow
{
    $report = $recap->build($viewer, ReportMonth::fromQuery($month, Desk::time($at)), now: Desk::time($at));

    return collect($report->people)->firstOrFail(fn (PersonRow $row) => $row->user->id === $userId);
}

test('a month adds up regular time, overtime by status, quiet time, short days and non-workday shifts', function () {
    ReportMonthScenario::play($this, $this->person, $this->lead);
    $this->travelTo(Desk::time('2026-10-05 10:00'));

    $row = reportRowFor(app(MonthlyRecap::class), $this->admin, '2026-09', $this->person->id, '2026-10-05 10:00');

    expect(collect($row->totals->toArray())->except('people')->all())->toEqual(ReportMonthScenario::TOTALS)
        ->and($row->lines)->toHaveCount(ReportMonthScenario::SHIFTS);
});

test('rejected and pending overtime are never added to approved overtime', function () {
    ReportMonthScenario::play($this, $this->person, $this->lead);

    $row = reportRowFor(app(MonthlyRecap::class), $this->admin, '2026-09', $this->person->id, '2026-10-05 10:00');

    expect($row->totals->overtimeApprovedMinutes)->toBe(120)
        ->and(array_sum(array_map(fn ($line) => $line->overtimeMinutes, $row->lines)))->toBe(120 + 240 + 30);
});

test('the per-day breakdown lists each shift with day type, times, overtime status, idle and notes', function () {
    ReportMonthScenario::play($this, $this->person, $this->lead);
    $this->travelTo(Desk::time('2026-10-05 10:00'));

    $this->actingAs($this->lead)
        ->get(route('reports.index', ['bulan' => '2026-09', 'orang' => $this->person->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/Index')
            ->where('report', null)
            ->where('detail.person.id', $this->person->id)
            ->where('detail.person.regular_minutes', ReportMonthScenario::TOTALS['regular_minutes'])
            ->has('detail.shifts', ReportMonthScenario::SHIFTS)
            ->where('detail.shifts.3.work_date', '2026-09-16')
            ->where('detail.shifts.3.overtime_minutes', 120)
            ->where('detail.shifts.3.overtime_status', 'approved')
            ->where('detail.shifts.5.overtime_status', 'rejected')
            ->where('detail.shifts.6.is_workday', false)
            ->where('detail.shifts.6.overtime_status', 'pending')
            ->where('detail.shifts.7.notes', ['short'])
            ->where('detail.shifts.8.idle_minutes', 30)
            ->where('detail.shifts.9.work_date', '2026-09-23')
            ->where('detail.shifts.9.clock_in_at', '2026-09-23T13:00:00.000Z')
            ->where('detail.shifts.9.clock_out_at', '2026-09-23T18:30:00.000Z')
            ->where('detail.shifts.9.regular_minutes', 330));
});

test('a shift belongs to the month of its Asia/Jakarta work date', function () {
    $desk = Desk::for($this, $this->person);

    // 23:30 WIB on 30 September, ending after midnight
    $desk->send('clock_in', at: '2026-09-30 23:30');
    $desk->send('clock_out', at: '2026-10-01 00:15');

    // 00:30 WIB on 1 October is still 30 September in UTC
    $desk->send('clock_in', at: '2026-10-01 00:30');
    $desk->send('clock_out', at: '04:30');

    $recap = app(MonthlyRecap::class);
    $september = reportRowFor($recap, $this->admin, '2026-09', $this->person->id, '2026-10-05 10:00');
    $october = reportRowFor($recap, $this->admin, '2026-10', $this->person->id, '2026-10-05 10:00');

    expect($september->lines)->toHaveCount(1)
        ->and($september->lines[0]->workDate)->toBe('2026-09-30')
        ->and($september->totals->regularMinutes)->toBe(45)
        ->and($september->totals->daysWorked)->toBe(1)
        ->and($october->lines)->toHaveCount(1)
        ->and($october->lines[0]->workDate)->toBe('2026-10-01')
        ->and($october->totals->regularMinutes)->toBe(240);
});

test('a running shift in the current month is read for this moment, the same as Hari ini', function () {
    $desk = Desk::for($this, $this->person);
    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->heartbeat('11:30');
    $this->travelTo(Desk::time('2026-09-14 11:31'));

    $saved = $desk->shift()->regular_minutes;
    $today = app(TodaySummary::class)->for($this->person, CarbonImmutable::now());
    $row = reportRowFor(app(MonthlyRecap::class), $this->lead, '2026-09', $this->person->id, '2026-09-14 11:31');

    expect($row->totals->regularMinutes)->toBe($today['regular_minutes'])
        ->and($row->totals->regularMinutes)->toBeGreaterThan($saved)
        ->and($row->totals->runningShifts)->toBe(1)
        ->and($row->lines[0]->notes())->toBe(['running']);
});

test('shifts closed for review and late overtime claims are counted, also before cron saves them', function () {
    $desk = Desk::for($this, $this->person);

    // Mon 14: the PC shuts down at 14:00 and nobody returns within 90 minutes (3.7.3)
    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->heartbeat('13:58');
    $desk->send('pc_shutdown', at: '14:00');
    $this->travelTo(Desk::time('2026-09-14 15:31'));

    $row = reportRowFor(app(MonthlyRecap::class), $this->lead, '2026-09', $this->person->id, '2026-09-14 15:31');

    expect($desk->shift()->clock_out_at)->toBeNull()
        ->and($row->totals->reviewShifts)->toBe(1)
        ->and($row->totals->runningShifts)->toBe(0)
        ->and($row->totals->regularMinutes)->toBe(300)
        ->and($row->lines[0]->notes())->toBe(['needs_review', 'short']);

    // Tue 15: the 8-hour prompt goes unanswered, the shift closes at the mark, overtime is claimed late (3.3.6)
    $desk->send('clock_in', at: '2026-09-15 09:00');
    $desk->heartbeat('17:40');
    $desk->heartbeat('19:05');
    $claimed = $desk->shift();
    $desk->at('2026-09-16 08:30');
    $desk->request('POST', "/api/v1/overtime/{$claimed->id}/claim", [
        'reason' => 'Masih render, prompt tidak terlihat',
        'work_report' => 'Render shot 7 dan 8',
        'ended_at' => '2026-09-15T12:00:00Z',
    ])->assertCreated();

    $row = reportRowFor(app(MonthlyRecap::class), $this->lead, '2026-09', $this->person->id, '2026-09-16 08:30');

    expect($row->totals->reviewShifts)->toBe(1)
        ->and($row->totals->lateClaims)->toBe(1)
        ->and($row->totals->overtimePendingMinutes)->toBe(120)
        ->and($row->totals->regularMinutes)->toBe(300 + 480)
        ->and($row->totals->daysWorked)->toBe(2)
        ->and($row->lines[1]->notes())->toBe(['late_claim']);
});

test('the page marks the current month as still changing and offers no later month', function () {
    $this->travelTo(Desk::time('2026-09-20 10:00'));

    $this->actingAs($this->lead)->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('month.value', '2026-09')
            ->where('month.is_current', true)
            ->where('month.next', null)
            ->where('month.previous', '2026-08'));

    $this->actingAs($this->lead)->get(route('reports.index', ['bulan' => '2026-08']))
        ->assertInertia(fn ($page) => $page
            ->where('month.is_current', false)
            ->where('month.next', '2026-09'));
});

test('active people without shifts get a row of zeros; people who left appear only in months they worked', function () {
    $left = userWithRole(Role::Employee);
    $this->team->members()->attach($left);

    $desk = Desk::for($this, $left);
    $desk->send('clock_in', at: '2026-09-15 09:00');
    $desk->send('clock_out', at: '12:00');
    $left->forceFill(['status' => UserStatus::Left])->save();

    $recap = app(MonthlyRecap::class);
    $now = Desk::time('2026-11-05 10:00');
    $ids = fn (string $month) => collect($recap->build($this->lead, ReportMonth::fromQuery($month, $now), now: $now)->people)->map(fn (PersonRow $r) => $r->user->id)->all();
    $idle = reportRowFor($recap, $this->lead, '2026-10', $this->person->id, '2026-11-05 10:00');

    expect($idle->totals->daysWorked)->toBe(0)
        ->and($idle->totals->regularMinutes)->toBe(0)
        ->and($ids('2026-09'))->toContain($left->id)
        ->and($ids('2026-10'))->not->toContain($left->id)
        ->and($ids('2026-10'))->toContain($this->person->id);
});
