<?php

use App\Modules\Attendance\Services\WeekTarget;
use App\Modules\Calendar\Enums\CalendarDayType;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\EmploymentType;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Database\Factories\ShiftFactory;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\Leave\Support\LeaveFixtures;

// docs/02 3.12: weekly work target per employment type. "Now" is Wednesday 23 September 2026 in the studio; the week
// is Monday 21 to Sunday 27 September.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 20:00', 'Asia/Jakarta'));
    $this->monday = CarbonImmutable::parse('2026-09-21', 'Asia/Jakarta');
});

function personOfType(EmploymentType $type, array $extra = []): User
{
    $user = userWithRole(Role::Employee);
    $user->forceFill(['employment_type' => $type, ...$extra])->save();

    return $user->refresh();
}

function workedDay(User $user, string $date, int $regular, int $overtime = 0): void
{
    ShiftFactory::new()->create([
        'user_id' => $user->id,
        'work_date' => $date,
        'clock_in_at' => CarbonImmutable::parse("{$date} 09:00", 'Asia/Jakarta')->utc(),
        'clock_out_at' => CarbonImmutable::parse("{$date} 09:00", 'Asia/Jakarta')->utc()->addMinutes($regular + $overtime),
        'last_seen_at' => CarbonImmutable::parse("{$date} 09:00", 'Asia/Jakarta')->utc()->addMinutes($regular + $overtime),
        'regular_minutes' => $regular,
        'overtime_minutes' => $overtime,
    ]);
}

it('asks 40 hours of regular time from permanent and contract staff, overtime left out', function () {
    $permanent = personOfType(EmploymentType::Permanent);
    $contract = personOfType(EmploymentType::Contract);
    workedDay($permanent, '2026-09-21', 480, 120);
    workedDay($permanent, '2026-09-22', 300);
    // Last week does not count
    workedDay($permanent, '2026-09-18', 480);

    $weeks = app(WeekTarget::class)->forMany(collect([$permanent, $contract]), $this->monday);

    expect($weeks[$permanent->id])->toMatchArray([
        'kind' => 'hours',
        'week_start' => '2026-09-21',
        'week_end' => '2026-09-27',
        'full_target_minutes' => 2400,
        'target_minutes' => 2400,
        'worked_minutes' => 780,
        'short_minutes' => 1620,
        'available_days' => 5,
        'attended_days' => 2,
        'target_days' => null,
    ])->and($weeks[$contract->id])->toMatchArray(['target_minutes' => 2400, 'worked_minutes' => 0]);
});

it('lowers the target by one day for each holiday or approved leave on a workday', function () {
    $person = personOfType(EmploymentType::Permanent);
    CalendarDay::query()->create(['date' => '2026-09-24', 'type' => CalendarDayType::Holiday, 'name' => 'Libur', 'created_by' => $person->id]);
    LeaveFixtures::request($person, '2026-09-21', '2026-09-21', LeaveStatus::Approved);
    // Pending leave changes nothing
    LeaveFixtures::request($person, '2026-09-25', '2026-09-25');

    expect(app(WeekTarget::class)->forUser($person, $this->monday))->toMatchArray([
        'full_target_minutes' => 2400,
        'target_minutes' => 3 * 480,
        'available_days' => 3,
        'leave_days' => 1,
    ]);
});

it('follows the weekly hours set on Aturan', function () {
    app(Settings::class)->set('target.weekly_hours', 35, null);

    expect(app(WeekTarget::class)->forUser(personOfType(EmploymentType::Permanent), $this->monday))
        ->toMatchArray(['target_minutes' => 2100]);
});

it('asks interns for days present times hours per day, with a per-person override', function () {
    $default = personOfType(EmploymentType::Intern);
    $own = personOfType(EmploymentType::Intern, ['intern_days_per_week' => 1, 'intern_minutes_per_day' => 300]);
    workedDay($default, '2026-09-21', 360);
    workedDay($own, '2026-09-22', 200);

    $weeks = app(WeekTarget::class)->forMany(collect([$default, $own]), $this->monday);

    expect($weeks[$default->id])->toMatchArray([
        'kind' => 'intern',
        'target_days' => 2,
        'minutes_per_day' => 480,
        'target_minutes' => 960,
        'worked_minutes' => 360,
        'attended_days' => 1,
    ])->and($weeks[$own->id])->toMatchArray([
        'target_days' => 1,
        'minutes_per_day' => 300,
        'target_minutes' => 300,
        'short_minutes' => 100,
    ]);
});

it('asks an intern for fewer days when the week has fewer workdays left', function () {
    $intern = personOfType(EmploymentType::Intern, ['intern_days_per_week' => 3]);
    LeaveFixtures::request($intern, '2026-09-21', '2026-09-23', LeaveStatus::Approved);

    expect(app(WeekTarget::class)->forUser($intern, $this->monday))->toMatchArray([
        'target_days' => 2,
        'target_minutes' => 960,
        'full_target_minutes' => 1440,
    ]);
});

it('gives freelancers no target anywhere', function () {
    $freelancer = personOfType(EmploymentType::Freelance);

    expect(app(WeekTarget::class)->forUser($freelancer, $this->monday))->toBeNull();

    $this->actingAs($freelancer)->get(route('my-day'))->assertInertia(fn ($page) => $page->where('week', null));
    $this->actingAs($freelancer)->get(route('history'))->assertInertia(fn ($page) => $page->where('weeks', null));
});

it('shows this week on Hari ini, and on Riwayat every started week of the month since the first shift', function () {
    $person = personOfType(EmploymentType::Permanent);
    workedDay($person, '2026-09-14', 480);
    workedDay($person, '2026-09-22', 240);

    $this->actingAs($person)->get(route('my-day'))->assertInertia(fn ($page) => $page
        ->where('week.week_start', '2026-09-21')
        ->where('week.worked_minutes', 240)
        ->where('week.short_minutes', 2160));

    $this->actingAs($person)->get(route('history', ['bulan' => '2026-09']))->assertInertia(fn ($page) => $page
        // From the week of the first shift (the 14th) to this week; the week of the 28th has not started yet
        ->has('weeks', 2)
        ->where('weeks.0.week_start', '2026-09-14')
        ->where('weeks.0.worked_minutes', 480)
        ->where('weeks.0.is_current', false)
        ->where('weeks.1.is_current', true));
});

it('counts a running shift as it stands now', function () {
    $person = personOfType(EmploymentType::Permanent);
    $this->travelTo(Desk::time('2026-09-23 09:00'));
    $desk = Desk::for($this, $person);
    $desk->send('clock_in', at: '2026-09-23 09:00')->assertOk();
    // The PC keeps signalling that it is on, or the shift would count as interrupted
    foreach (['09:02', '09:04', '09:06', '09:08', '09:10'] as $time) {
        $desk->heartbeat("2026-09-23 {$time}")->assertOk();
    }
    $this->travelTo(Desk::time('2026-09-23 09:10'));

    expect(app(WeekTarget::class)->forUser($person, $this->monday))->toMatchArray(['worked_minutes' => 10, 'attended_days' => 1]);
});

it('shows the week of each person on Tim hari ini', function () {
    $pm = userWithRole(Role::ProjectManager);
    $intern = personOfType(EmploymentType::Intern);
    $freelancer = personOfType(EmploymentType::Freelance);

    $this->actingAs($pm)->get(route('team.today'))->assertInertia(fn ($page) => $page
        ->where('board.people', fn ($people) => collect($people)->firstWhere('id', $intern->id)['week']['target_minutes'] === 960
            && collect($people)->firstWhere('id', $freelancer->id)['week'] === null));
});
