<?php

use App\Modules\Calendar\Enums\CalendarDayType;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Identity\Access\Role;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Services\LeaveDays;
use App\Modules\Organization\Models\Team;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\Leave\Support\LeaveFixtures;

beforeEach(function () {
    $this->travelTo(Desk::time('2026-09-14 10:00'));
    $this->admin = userWithRole(Role::Superadmin);
    $this->lead = userWithRole(Role::TeamLead);
    $this->person = userWithRole(Role::Employee);
    $this->team = Team::factory()->create(['name' => 'Animation', 'lead_user_id' => $this->lead->id]);
    $this->team->members()->attach([$this->person->id, $this->lead->id]);
});

it('returns approved leave workdays per person, cut to the range, with every asked id as a key', function () {
    $other = userWithRole(Role::Employee);
    $quiet = userWithRole(Role::Employee);
    CalendarDay::query()->create(['date' => '2026-09-17', 'type' => CalendarDayType::Holiday, 'name' => 'Libur', 'created_by' => $this->admin->id]);

    LeaveFixtures::request($this->person, '2026-09-16', '2026-09-22', LeaveStatus::Approved);
    LeaveFixtures::request($this->person, '2026-09-30', '2026-10-02', LeaveStatus::Approved, 'sick');
    LeaveFixtures::request($other, '2026-09-21', '2026-09-21', LeaveStatus::Pending);
    LeaveFixtures::request($other, '2026-09-22', '2026-09-22', LeaveStatus::Rejected);

    $dates = app(LeaveDays::class)->approvedDates([$this->person->id, $other->id, $quiet->id], '2026-09-01', '2026-09-30');

    expect($dates)->toBe([
        $this->person->id => ['2026-09-16', '2026-09-18', '2026-09-21', '2026-09-22', '2026-09-30'],
        $other->id => [],
        $quiet->id => [],
    ]);
});

it('recounts the days of open and approved requests when the calendar changes', function () {
    $pending = LeaveFixtures::request($this->person, '2026-10-05', '2026-10-09');
    $rejected = LeaveFixtures::request($this->person, '2026-10-05', '2026-10-09', LeaveStatus::Rejected);

    $this->actingAs($this->admin)
        ->post(route('calendar.days.store'), ['date' => '2026-10-07', 'type' => 'holiday', 'name' => 'Libur contoh'])
        ->assertSessionHasNoErrors();

    expect($pending->refresh()->days)->toBe(4)
        ->and($rejected->refresh()->days)->toBe(5);
});

it('shows a person with approved leave today as on leave on Tim hari ini', function () {
    $pendingPerson = userWithRole(Role::Employee);
    $this->team->members()->attach($pendingPerson);
    LeaveFixtures::request($this->person, '2026-09-14', '2026-09-15', LeaveStatus::Approved, 'sick');
    LeaveFixtures::request($pendingPerson, '2026-09-14', '2026-09-14');

    $people = collect($this->actingAs($this->lead)->get(route('team.today'))->assertOk()->viewData('page')['props']['board']['people'])->keyBy('id');

    expect($people[$this->person->id])->toMatchArray(['group' => 'leave', 'status' => 'leave', 'eyes' => 'closed', 'leave' => ['type' => 'Sakit']])
        ->and($people[$pendingPerson->id])->toMatchArray(['group' => 'not_started', 'leave' => null]);
});

it('keeps clocking in on a leave day as ordinary attendance', function () {
    LeaveFixtures::request($this->person, '2026-09-14', '2026-09-14', LeaveStatus::Approved);

    $desk = Desk::for($this, $this->person);
    $desk->send('clock_in', at: '09:00');
    $desk->heartbeat('10:59');
    $this->travelTo(Desk::time('2026-09-14 11:00'));

    $row = collect($this->actingAs($this->lead)->get(route('team.today'))->viewData('page')['props']['board']['people'])->firstWhere('id', $this->person->id);

    expect($row)->toMatchArray(['group' => 'working', 'status' => 'open', 'regular_minutes' => 120, 'leave' => ['type' => 'Cuti tahunan']]);
});

it('adds leave days per person to the report and the Excel summary', function () {
    LeaveFixtures::request($this->person, '2026-09-21', '2026-09-23', LeaveStatus::Approved);
    LeaveFixtures::request($this->person, '2026-09-30', '2026-10-01', LeaveStatus::Approved, 'sick');
    LeaveFixtures::request($this->person, '2026-09-24', '2026-09-24', LeaveStatus::Pending);
    $this->travelTo(Desk::time('2026-10-05 10:00'));

    $report = $this->actingAs($this->admin)->get(route('reports.index', ['bulan' => '2026-09']))->assertOk()->viewData('page')['props']['report'];
    $row = collect($report['groups'])->flatMap(fn ($g) => $g['people'])->firstWhere('id', $this->person->id);

    expect($row['leave_days'])->toBe(4)
        ->and($report['total']['leave_days'])->toBe(4);

    $response = $this->actingAs($this->admin)->get(route('reports.export', ['bulan' => '2026-09']))->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();

    try {
        $sheet = IOFactory::load($path)->getSheetByName('Ringkasan')->rangeToArray('A1:T20', null, false, false, false);
    } finally {
        @unlink($path);
    }

    $personRow = collect($sheet)->first(fn ($r) => $r[2] === $this->person->username);

    expect($sheet[0][19])->toBe('Hari cuti')
        ->and($personRow[19])->toBe(4);
});

it('counts leave in the running month only up to today, and none in a month to come', function () {
    LeaveFixtures::request($this->person, '2026-09-14', '2026-09-16', LeaveStatus::Approved);
    LeaveFixtures::request($this->person, '2026-09-21', '2026-09-22', LeaveStatus::Approved, 'sick');
    LeaveFixtures::request($this->person, '2026-10-05', '2026-10-06', LeaveStatus::Approved);
    $leaveDays = function (string $month): int {
        $report = $this->actingAs($this->admin)->get(route('reports.index', ['bulan' => $month]))->assertOk()->viewData('page')['props']['report'];

        return collect($report['groups'])->flatMap(fn ($g) => $g['people'])->firstWhere('id', $this->person->id)['leave_days'];
    };
    // Tuesday 15 September, late in the studio evening: today counts, tomorrow does not
    $this->travelTo(Desk::time('2026-09-15 23:30'));

    expect($leaveDays('2026-09'))->toBe(2)
        ->and($leaveDays('2026-10'))->toBe(0);

    $this->travelTo(Desk::time('2026-10-01 08:00'));

    expect($leaveDays('2026-09'))->toBe(5)
        ->and($leaveDays('2026-10'))->toBe(0);
});
