<?php

use App\Modules\Calendar\Enums\CalendarDayType;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Monitoring\Services\Workload;
use App\Modules\Projects\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ShiftFactory;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Leave\Support\LeaveFixtures;
use Tests\Feature\WorkMonitor\Support\WorkFixtures as W;

// docs/14 5.2: Beban kerja. "Now" is Wednesday 23 September 2026 in the studio; the week is 21 to 27 September.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Jakarta'));
    $this->viewer = userWithRole(Role::ProjectManager);
});

function workloadProps(User $viewer, array $query = []): array
{
    return test()->actingAs($viewer)->get(route('monitoring.workload', $query))->assertOk()->original->getData()['page']['props'];
}

/** @return array<string, mixed> */
function workloadRow(array $props, User $person): array
{
    return collect($props['people'])->firstWhere('person.id', $person->id) ?? throw new RuntimeException('Person not in the list.');
}

it('leaves management out: whoever may open Beban kerja is not planned on it', function () {
    $employee = userWithRole(Role::Employee);
    $lead = userWithRole(Role::TeamLead);
    W::team('Animasi', $lead, $employee, $lead);
    $others = [userWithRole(Role::ProjectDirector), userWithRole(Role::Superadmin), userWithRole(Role::Employee, Role::TeamLead)];

    $ids = collect(workloadProps($this->viewer)['people'])->pluck('person.id');

    expect($ids->all())->toBe([$employee->id])
        ->and($ids)->not->toContain($this->viewer->id, $lead->id, ...collect($others)->pluck('id')->all());

    // A Team Lead who is also a member of their own team only sees the employee
    expect(collect(workloadProps($lead)['people'])->pluck('person.id')->all())->toBe([$employee->id]);
});

it('works out capacity from workdays minus holidays and approved leave', function () {
    $person = userWithRole(Role::Employee);
    CalendarDay::query()->create(['date' => '2026-09-24', 'type' => CalendarDayType::Holiday, 'name' => 'Libur', 'created_by' => $this->viewer->id]);
    LeaveFixtures::request($person, '2026-09-21', '2026-09-22', LeaveStatus::Approved);
    // Pending leave does not reduce capacity
    LeaveFixtures::request($person, '2026-09-25', '2026-09-25');

    $row = workloadRow(workloadProps($this->viewer), $person);

    // Monday to Friday is 5 workdays, Thursday is a holiday, Monday and Tuesday are leave
    expect($row)->toMatchArray(['workdays' => 4, 'leave_days' => 2, 'capacity_minutes' => 2 * 480]);
});

it('plans the remaining estimate of open tasks due this week or overdue', function () {
    $person = userWithRole(Role::Employee);
    $sub = W::sub(W::project('Film Pendek'), 'Episode 1');

    $half = W::task($sub, $person, TaskStatus::InProgress, ['due_date' => '2026-09-25', 'estimate_minutes' => 600]);
    W::session($half, $person, 240);
    W::session($half, userWithRole(Role::Employee), 60);
    $over = W::task($sub, $person, TaskStatus::ChangesRequested, ['due_date' => '2026-09-10', 'estimate_minutes' => 120]);
    W::session($over, $person, 300);
    W::task($sub, $person, TaskStatus::Todo, ['due_date' => '2026-09-27', 'estimate_minutes' => 60]);
    // Not counted: next week, no due date, in review, done, someone else's
    W::task($sub, $person, TaskStatus::Todo, ['due_date' => '2026-09-28', 'estimate_minutes' => 999]);
    W::task($sub, $person, TaskStatus::Todo, ['estimate_minutes' => 999]);
    W::task($sub, $person, TaskStatus::InReview, ['due_date' => '2026-09-24', 'estimate_minutes' => 999]);
    W::task($sub, $person, TaskStatus::Done, ['due_date' => '2026-09-24', 'estimate_minutes' => 999]);
    W::task($sub, userWithRole(Role::Employee), TaskStatus::Todo, ['due_date' => '2026-09-24', 'estimate_minutes' => 999]);

    // 600 - 300 = 300, 120 - 300 floors at 0, 60 untouched
    expect(workloadRow(workloadProps($this->viewer), $person))->toMatchArray(['planned_minutes' => 360, 'planned_tasks' => 3, 'without_estimate' => 0]);
});

it('splits what is left of a shared estimate evenly among its assignees', function () {
    $rani = userWithRole(Role::Employee);
    $bayu = userWithRole(Role::Employee);
    $sub = W::sub(W::project('Film Pendek'), 'Episode 1');

    W::task($sub, [$rani, $bayu], TaskStatus::Todo, ['due_date' => '2026-09-25', 'estimate_minutes' => 600]);

    $props = workloadProps($this->viewer);
    expect(workloadRow($props, $rani)['planned_minutes'])->toBe(300)
        ->and(workloadRow($props, $bayu)['planned_minutes'])->toBe(300);

    // Timer minutes of anyone on the task come off before the split: (600 - 120) / 2
    $started = W::task($sub, [$rani, $bayu], TaskStatus::InProgress, ['due_date' => '2026-09-25', 'estimate_minutes' => 600]);
    W::session($started, $rani, 90);
    W::session($started, $bayu, 30);

    $props = workloadProps($this->viewer);
    expect(workloadRow($props, $rani))->toMatchArray(['planned_minutes' => 540, 'planned_tasks' => 2])
        ->and(workloadRow($props, $bayu))->toMatchArray(['planned_minutes' => 540, 'planned_tasks' => 2]);
});

it('plans a shared task only for people whose own part is still to do', function () {
    $rani = userWithRole(Role::Employee);
    $bayu = userWithRole(Role::Employee);
    $sub = W::sub(W::project('Film Pendek'), 'Episode 1');

    $task = W::task($sub, [$rani, $bayu], TaskStatus::InProgress, ['due_date' => '2026-09-25', 'estimate_minutes' => 600]);
    $task->parts()->where('user_id', $bayu->id)->update(['part_status' => 'submitted']);
    W::task($sub, [$rani, $bayu], TaskStatus::ChangesRequested, ['due_date' => '2026-09-25']);

    $props = workloadProps($this->viewer);

    // Bayu sent his part: it waits for the lead. Rani keeps her half; the other task has no estimate
    expect(workloadRow($props, $rani))->toMatchArray(['planned_minutes' => 300, 'planned_tasks' => 2, 'without_estimate' => 1])
        ->and(workloadRow($props, $bayu))->toMatchArray(['planned_minutes' => 0, 'planned_tasks' => 1, 'without_estimate' => 1]);
});

it('counts open tasks without an estimate separately', function () {
    $person = userWithRole(Role::Employee);
    $sub = W::sub(W::project('Film Pendek'), 'Episode 1');
    W::task($sub, $person, TaskStatus::Todo, ['due_date' => '2026-09-24']);
    W::task($sub, $person, TaskStatus::InProgress, ['due_date' => '2026-09-01']);
    W::task($sub, $person, TaskStatus::Todo, ['due_date' => '2026-09-24', 'estimate_minutes' => 30]);

    expect(workloadRow(workloadProps($this->viewer), $person))->toMatchArray(['planned_minutes' => 30, 'planned_tasks' => 3, 'without_estimate' => 2]);
});

it('adds work log hours and regular attendance hours of that week', function () {
    $person = userWithRole(Role::Employee);
    $film = W::project('Film Pendek');
    W::log($person, $film, 90, '2026-09-21 02:00:00');
    W::log($person, $film, 30, '2026-09-20 16:30:00'); // Sunday 23.30 in Jakarta: last week
    W::log($person, $film, 45, '2026-09-22 02:00:00')->delete();
    ShiftFactory::new()->create(['user_id' => $person->id, 'work_date' => '2026-09-21', 'regular_minutes' => 480]);
    ShiftFactory::new()->create(['user_id' => $person->id, 'work_date' => '2026-09-22', 'regular_minutes' => 300,
        'clock_in_at' => CarbonImmutable::parse('2026-09-22 02:00:00', 'UTC'), 'clock_out_at' => CarbonImmutable::parse('2026-09-22 07:00:00', 'UTC')]);
    ShiftFactory::new()->create(['user_id' => $person->id, 'work_date' => '2026-09-18', 'regular_minutes' => 480,
        'clock_in_at' => CarbonImmutable::parse('2026-09-18 02:00:00', 'UTC'), 'clock_out_at' => CarbonImmutable::parse('2026-09-18 10:00:00', 'UTC')]);

    expect(workloadRow(workloadProps($this->viewer), $person))->toMatchArray(['logged_minutes' => 90, 'regular_minutes' => 780]);
});

it('names the status by the planned share of capacity', function () {
    expect(Workload::status(0, 2400))->toBe('loose')
        ->and(Workload::status(1199, 2400))->toBe('loose')
        ->and(Workload::status(1200, 2400))->toBe('fit')
        ->and(Workload::status(1919, 2400))->toBe('fit')
        ->and(Workload::status(1920, 2400))->toBe('full')
        ->and(Workload::status(2400, 2400))->toBe('full')
        ->and(Workload::status(2401, 2400))->toBe('over')
        ->and(Workload::status(60, 0))->toBe('over')
        ->and(Workload::status(0, 0))->toBe('no_capacity');
});

it('puts overloaded people first and leaves out people who left', function () {
    $sub = W::sub(W::project('Film Pendek'), 'Episode 1');
    $calm = User::factory()->withRole(Role::Employee)->create(['name' => 'Adi']);
    $busy = User::factory()->withRole(Role::Employee)->create(['name' => 'Zul']);
    $left = User::factory()->withRole(Role::Employee)->create(['name' => 'Bayu', 'status' => UserStatus::Left]);
    W::task($sub, $busy, TaskStatus::Todo, ['due_date' => '2026-09-24', 'estimate_minutes' => 5 * 480 + 1]);
    W::task($sub, $left, TaskStatus::Todo, ['due_date' => '2026-09-24', 'estimate_minutes' => 60]);

    $props = workloadProps($this->viewer);
    $ids = collect($props['people'])->pluck('person.id');

    expect($ids->first())->toBe($busy->id)
        ->and($ids)->toContain($calm->id)->not->toContain($left->id)
        ->and(workloadRow($props, $busy)['status'])->toBe('over')
        ->and($props['counts']['over'])->toBe(1);
});

it('moves between weeks with the Monday of the week in the query', function () {
    $props = workloadProps($this->viewer, ['minggu' => '2026-09-30']);
    expect($props['week'])->toBe([
        'start' => '2026-09-28', 'end' => '2026-10-04', 'previous' => '2026-09-21', 'next' => '2026-10-05',
        'current' => '2026-09-21', 'is_current' => false,
    ]);

    expect(workloadProps($this->viewer, ['minggu' => 'kemarin'])['week']['start'])->toBe('2026-09-21')
        ->and(workloadProps($this->viewer, ['minggu' => '2026-02-30'])['week']['is_current'])->toBeTrue();
});

it('reads the week in the same number of queries however many people it lists', function () {
    $sub = W::sub(W::project('Film Pendek'), 'Episode 1');
    $team = W::team('Animasi', userWithRole(Role::TeamLead));
    $addPerson = function () use ($sub, $team) {
        $person = userWithRole(Role::Employee);
        $team->members()->attach($person);
        W::task($sub, $person, TaskStatus::Todo, ['due_date' => '2026-09-24', 'estimate_minutes' => 120]);
        LeaveFixtures::request($person, '2026-09-22', '2026-09-22', LeaveStatus::Approved);
    };
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('monitoring.workload'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $addPerson();
    // The first request also warms the permission cache
    $this->actingAs($this->viewer)->get(route('monitoring.workload'))->assertOk();
    $few = $queries();

    foreach (range(1, 6) as $i) {
        $addPerson();
    }

    expect($queries())->toBe($few)->toBeLessThan(40)
        ->and(count(workloadProps($this->viewer)['people']))->toBeGreaterThanOrEqual(7);
});
