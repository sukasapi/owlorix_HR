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
