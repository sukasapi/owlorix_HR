<?php

use App\Modules\Calendar\Enums\CalendarDayType;
use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Calendar\Models\WorkWeekDay;
use App\Modules\Calendar\Services\DayVerdict;
use App\Modules\Calendar\Services\WorkdayResolver;
use App\Modules\Identity\Access\Role;
use App\Modules\Organization\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->resolver = app(WorkdayResolver::class);
    $this->person = userWithRole(Role::Employee);
    $this->admin = userWithRole(Role::Superadmin);
    $this->lead = userWithRole(Role::TeamLead);
});

// 2026-09-14 is a Monday, 2026-09-19 a Saturday.

it('uses Monday to Friday as the default work week', function () {
    expect($this->resolver->isWorkday($this->person, '2026-09-14'))->toBeTrue()
        ->and($this->resolver->isWorkday($this->person, '2026-09-18'))->toBeTrue()
        ->and($this->resolver->isWorkday($this->person, '2026-09-19'))->toBeFalse()
        ->and($this->resolver->isWorkday($this->person, '2026-09-20'))->toBeFalse();
});

it('follows the work week Superadmin sets', function () {
    WorkWeekDay::query()->whereKey(6)->update(['is_workday' => true]);

    expect($this->resolver->isWorkday($this->person, '2026-09-19'))->toBeTrue();
});

it('treats a holiday or studio day off on a weekday as a non-workday', function (CalendarDayType $type) {
    CalendarDay::query()->create(['date' => '2026-09-16', 'type' => $type, 'name' => 'Libur', 'created_by' => $this->admin->id]);

    $verdict = $this->resolver->verdict($this->person, '2026-09-16');

    expect($verdict->isWorkday)->toBeFalse()
        ->and($verdict->source)->toBe(DayVerdict::SOURCE_CALENDAR)
        ->and($verdict->label)->toBe('Libur');
})->with([CalendarDayType::Holiday, CalendarDayType::StudioDayOff]);

it('turns a weekend date into a studio-wide workday', function () {
    CalendarDay::query()->create(['date' => '2026-09-19', 'type' => CalendarDayType::Workday, 'name' => 'Ganti libur', 'created_by' => $this->admin->id]);

    expect($this->resolver->isWorkday($this->person, '2026-09-19'))->toBeTrue();
});

it('opens a Saturday for one team only', function () {
    $team = Team::factory()->create();
    $team->members()->attach($this->person);
    $outsider = userWithRole(Role::Employee);

    OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::Team, 'scope_id' => $team->id, 'opened_by' => $this->lead->id]);

    expect($this->resolver->verdict($this->person, '2026-09-19'))
        ->isWorkday->toBeTrue()
        ->source->toBe(DayVerdict::SOURCE_OPENED)
        ->and($this->resolver->isWorkday($outsider, '2026-09-19'))->toBeFalse();
});

it('opens a date for one person', function () {
    OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::User, 'scope_id' => $this->person->id, 'opened_by' => $this->lead->id]);

    expect($this->resolver->isWorkday($this->person, '2026-09-19'))->toBeTrue();
});

it('lets an opened date win over a public holiday', function () {
    CalendarDay::query()->create(['date' => '2026-09-16', 'type' => CalendarDayType::Holiday, 'name' => 'Libur', 'created_by' => $this->admin->id]);
    OpenedWorkday::query()->create(['date' => '2026-09-16', 'scope_type' => OpenedScope::User, 'scope_id' => $this->person->id, 'opened_by' => $this->lead->id]);

    expect($this->resolver->isWorkday($this->person, '2026-09-16'))->toBeTrue();
});

it('ignores an opened date that was closed again', function () {
    $opened = OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::User, 'scope_id' => $this->person->id, 'opened_by' => $this->lead->id]);
    $opened->delete();

    expect($this->resolver->isWorkday($this->person, '2026-09-19'))->toBeFalse();
});

it('returns every date of a range in order', function () {
    $days = $this->resolver->range($this->person, '2026-09-14', '2026-09-20');

    expect(array_keys($days))->toBe(['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20'])
        ->and(array_map(fn (DayVerdict $d) => $d->isWorkday, array_values($days)))->toBe([true, true, true, true, true, false, false]);
});

it('reads several people at once exactly as it reads each of them', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $inTeam = userWithRole(Role::Employee);
    $inBoth = userWithRole(Role::Employee);
    $opened = userWithRole(Role::Employee);
    $nobody = userWithRole(Role::Employee);
    $team->members()->attach([$inTeam->id, $inBoth->id]);
    $otherTeam->members()->attach($inBoth);

    // Wednesday is a holiday, the next Saturday a studio-wide workday
    CalendarDay::query()->create(['date' => '2026-09-16', 'type' => CalendarDayType::Holiday, 'name' => 'Libur', 'created_by' => $this->admin->id]);
    CalendarDay::query()->create(['date' => '2026-09-26', 'type' => CalendarDayType::Workday, 'name' => 'Ganti libur', 'created_by' => $this->admin->id]);
    // Opened: Saturday for the first team, the holiday for one person, Sunday for the second team, a closed one
    OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::Team, 'scope_id' => $team->id, 'opened_by' => $this->lead->id]);
    OpenedWorkday::query()->create(['date' => '2026-09-16', 'scope_type' => OpenedScope::User, 'scope_id' => $opened->id, 'opened_by' => $this->lead->id]);
    OpenedWorkday::query()->create(['date' => '2026-09-20', 'scope_type' => OpenedScope::Team, 'scope_id' => $otherTeam->id, 'opened_by' => $this->lead->id]);
    OpenedWorkday::query()->create(['date' => '2026-09-27', 'scope_type' => OpenedScope::User, 'scope_id' => $nobody->id, 'opened_by' => $this->lead->id])->delete();

    $people = [$inTeam, $inBoth, $opened, $nobody];
    $many = $this->resolver->rangeMany($people, '2026-09-14', '2026-09-27');

    $one = [];
    foreach ($people as $person) {
        $one[$person->id] = array_keys(array_filter($this->resolver->range($person, '2026-09-14', '2026-09-27'), fn (DayVerdict $d) => $d->isWorkday));
    }

    $weekdays = ['2026-09-14', '2026-09-15', '2026-09-17', '2026-09-18', '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26'];
    $sorted = fn (array $dates) => collect($dates)->sort()->values()->all();

    expect($many)->toBe($one)
        ->and($many[$nobody->id])->toBe($weekdays)
        ->and($many[$inTeam->id])->toBe($sorted([...$weekdays, '2026-09-19']))
        ->and($many[$inBoth->id])->toBe($sorted([...$weekdays, '2026-09-19', '2026-09-20']))
        ->and($many[$opened->id])->toBe($sorted([...$weekdays, '2026-09-16']))
        ->and($this->resolver->rangeMany([$nobody->id], '2026-09-19', '2026-09-19'))->toBe([$nobody->id => []])
        ->and($this->resolver->rangeMany([], '2026-09-14', '2026-09-20'))->toBe([]);
});

it('reads several people in the same number of queries as one', function () {
    $team = Team::factory()->create();
    $count = function (Collection|array $people): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->resolver->rangeMany($people, '2026-09-14', '2026-09-20');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $one = $count([$this->person]);
    $people = collect(range(1, 6))->map(fn () => userWithRole(Role::Employee));
    $team->members()->attach($people->pluck('id')->all());

    expect($count($people))->toBe($one)->toBe(4);
});
