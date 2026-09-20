<?php

use App\Modules\Calendar\Events\CalendarDatesChanged;
use App\Modules\Calendar\Models\WorkWeekDay;
use App\Modules\Identity\Access\Role;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([CalendarDatesChanged::class]);
    $this->admin = userWithRole(Role::Superadmin);
});

function calendarTestWorkdays(): array
{
    return WorkWeekDay::query()->where('is_workday', true)->orderBy('weekday')->pluck('weekday')->map(fn ($d) => (int) $d)->all();
}

it('lets Superadmin change the work week, with an audit row and an event for all dates', function () {
    $this->actingAs($this->admin)
        ->from(route('calendar.index'))
        ->put(route('calendar.work-week.update'), ['workdays' => [6, 1, 2, 3, 4, 5]])
        ->assertRedirect(route('calendar.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    expect(calendarTestWorkdays())->toBe([1, 2, 3, 4, 5, 6])
        ->and(WorkWeekDay::query()->find(6)->updated_by)->toBe($this->admin->id);

    $log = AuditLog::query()->where('action', 'calendar.work_week.updated')->sole();
    expect($log->actor_id)->toBe($this->admin->id)
        ->and($log->before)->toBe(['workdays' => [1, 2, 3, 4, 5]])
        ->and($log->after)->toBe(['workdays' => [1, 2, 3, 4, 5, 6]]);

    Event::assertDispatchedTimes(CalendarDatesChanged::class, 1);
    Event::assertDispatched(CalendarDatesChanged::class, fn (CalendarDatesChanged $e) => $e->allDates === true && $e->dates === [] && $e->userIds === null);
});

it('allows a week with no workdays at all', function () {
    $this->actingAs($this->admin)
        ->put(route('calendar.work-week.update'), ['workdays' => []])
        ->assertSessionHasNoErrors();

    expect(calendarTestWorkdays())->toBe([]);
});

it('writes nothing when the work week did not change', function () {
    $this->actingAs($this->admin)
        ->put(route('calendar.work-week.update'), ['workdays' => [5, 4, 3, 2, 1]])
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->count())->toBe(0);
    Event::assertNotDispatched(CalendarDatesChanged::class);
});

it('rejects weekdays outside 1 to 7, duplicates, and a missing list', function (array $payload, string $errorKey) {
    $this->actingAs($this->admin)
        ->put(route('calendar.work-week.update'), $payload)
        ->assertSessionHasErrors($errorKey);

    expect(calendarTestWorkdays())->toBe([1, 2, 3, 4, 5]);
})->with([
    'weekday 8' => [['workdays' => [1, 8]], 'workdays.1'],
    'weekday 0' => [['workdays' => [0]], 'workdays.0'],
    'duplicate' => [['workdays' => [1, 1]], 'workdays.0'],
    'missing' => [[], 'workdays'],
    'not a list' => [['workdays' => 'senin'], 'workdays'],
]);

it('refuses the work week to Management', function (Role $role) {
    $this->actingAs(userWithRole($role))
        ->put(route('calendar.work-week.update'), ['workdays' => [1, 2, 3, 4, 5, 6]])
        ->assertForbidden();

    expect(calendarTestWorkdays())->toBe([1, 2, 3, 4, 5]);
})->with([Role::TeamLead, Role::ProjectManager, Role::ProjectDirector, Role::Employee]);
