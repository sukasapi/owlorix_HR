<?php

use App\Modules\Calendar\Enums\CalendarDayType;
use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Calendar\Events\CalendarDatesChanged;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Calendar\Services\WorkdayResolver;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use App\Modules\Shared\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

// 2026-09-19 is a Saturday, 2026-09-16 a Wednesday.

beforeEach(function () {
    Event::fake([CalendarDatesChanged::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-14 03:00:00', 'UTC'));

    $this->lead = userWithRole(Role::TeamLead);
    $this->member = userWithRole(Role::Employee);
    $this->otherMember = userWithRole(Role::Employee);
    $this->outsider = userWithRole(Role::Employee);

    $this->team = Team::factory()->create(['name' => 'Animation', 'lead_user_id' => $this->lead->id]);
    $this->team->members()->attach([$this->member->id, $this->otherMember->id]);

    $this->otherTeam = Team::factory()->create(['name' => 'Modeling']);
    $this->otherTeam->members()->attach($this->outsider);
});

it('lets a Team Lead open a Saturday for the team they lead', function () {
    $this->actingAs($this->lead)
        ->from(route('calendar.index'))
        ->post(route('calendar.opened.store'), ['date' => '2026-09-19', 'scope_type' => 'team', 'scope_id' => $this->team->id, 'note' => 'Kejar render'])
        ->assertRedirect(route('calendar.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    $opened = OpenedWorkday::query()->sole();
    expect($opened)
        ->date->toDateString()->toBe('2026-09-19')
        ->scope_type->toBe(OpenedScope::Team)
        ->scope_id->toBe($this->team->id)
        ->opened_by->toBe($this->lead->id)
        ->note->toBe('Kejar render');

    expect(app(WorkdayResolver::class)->isWorkday($this->member, '2026-09-19'))->toBeTrue()
        ->and(app(WorkdayResolver::class)->isWorkday($this->outsider, '2026-09-19'))->toBeFalse();

    $log = AuditLog::query()->where('action', 'calendar.opened_workday.opened')->sole();
    expect($log->actor_id)->toBe($this->lead->id)
        ->and($log->subject_id)->toBe($opened->id)
        ->and($log->before)->toBeNull()
        ->and($log->after)->toEqual(['date' => '2026-09-19', 'scope_type' => 'team', 'scope_id' => $this->team->id, 'opened_by' => $this->lead->id, 'note' => 'Kejar render']);

    $memberIds = collect([$this->member->id, $this->otherMember->id])->sort()->values()->all();
    Event::assertDispatched(CalendarDatesChanged::class, fn (CalendarDatesChanged $e) => $e->dates === ['2026-09-19'] && $e->userIds === $memberIds && ! $e->allDates);
});

it('lets a Team Lead open a date for a member of their team', function () {
    $this->actingAs($this->lead)
        ->post(route('calendar.opened.store'), ['date' => '2026-09-19', 'scope_type' => 'user', 'scope_id' => $this->member->id])
        ->assertSessionHasNoErrors();

    expect(OpenedWorkday::query()->sole()->note)->toBeNull();
    Event::assertDispatched(CalendarDatesChanged::class, fn (CalendarDatesChanged $e) => $e->dates === ['2026-09-19'] && $e->userIds === [$this->member->id]);
});

it('refuses a Team Lead outside the teams they lead', function (string $scopeType) {
    $scopeId = $scopeType === 'team' ? $this->otherTeam->id : $this->outsider->id;

    $this->actingAs($this->lead)
        ->post(route('calendar.opened.store'), ['date' => '2026-09-19', 'scope_type' => $scopeType, 'scope_id' => $scopeId])
        ->assertForbidden();

    expect(OpenedWorkday::query()->count())->toBe(0);
    Event::assertNotDispatched(CalendarDatesChanged::class);
})->with([
    'another team' => ['team'],
    'a person outside their teams' => ['user'],
]);

it('refuses a Team Lead who is only a member of a team, not its lead', function () {
    $memberLead = userWithRole(Role::TeamLead);
    $this->otherTeam->members()->attach($memberLead);

    $this->actingAs($memberLead)
        ->post(route('calendar.opened.store'), ['date' => '2026-09-19', 'scope_type' => 'team', 'scope_id' => $this->otherTeam->id])
        ->assertForbidden();
});

it('lets Project Managers, Project Directors, and Superadmin open a date for any team or person', function (Role $role) {
    $viewer = userWithRole($role);

    $this->actingAs($viewer)
        ->post(route('calendar.opened.store'), ['date' => '2026-09-19', 'scope_type' => 'team', 'scope_id' => $this->otherTeam->id])
        ->assertSessionHasNoErrors();

    $this->actingAs($viewer)
        ->post(route('calendar.opened.store'), ['date' => '2026-09-20', 'scope_type' => 'user', 'scope_id' => $this->member->id])
        ->assertSessionHasNoErrors();

    expect(OpenedWorkday::query()->count())->toBe(2);
})->with([Role::ProjectManager, Role::ProjectDirector, Role::Superadmin]);

it('refuses an employee', function () {
    $this->actingAs($this->member)
        ->post(route('calendar.opened.store'), ['date' => '2026-09-19', 'scope_type' => 'user', 'scope_id' => $this->member->id])
        ->assertForbidden();
});

it('refuses to open a date that is already a studio workday', function () {
    $this->actingAs($this->lead)
        ->post(route('calendar.opened.store'), ['date' => '2026-09-16', 'scope_type' => 'team', 'scope_id' => $this->team->id])
        ->assertSessionHasErrors(['date' => __('calendar::messages.already_workday_team', ['date' => 'Rabu, 16 September 2026', 'scope' => 'Animation'])]);

    expect(OpenedWorkday::query()->count())->toBe(0);
    Event::assertNotDispatched(CalendarDatesChanged::class);
});

it('refuses to open a date for a person whose team already has it open', function () {
    OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::Team, 'scope_id' => $this->team->id, 'opened_by' => $this->lead->id]);

    $this->actingAs($this->lead)
        ->post(route('calendar.opened.store'), ['date' => '2026-09-19', 'scope_type' => 'user', 'scope_id' => $this->member->id])
        ->assertSessionHasErrors(['date' => __('calendar::messages.already_workday_user', ['date' => 'Sabtu, 19 September 2026', 'scope' => $this->member->name])]);

    expect(OpenedWorkday::query()->count())->toBe(1);
});

it('refuses a team when every member already has the date as a workday', function () {
    foreach ([$this->member, $this->otherMember] as $person) {
        OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::User, 'scope_id' => $person->id, 'opened_by' => $this->lead->id]);
    }

    $this->actingAs($this->lead)
        ->post(route('calendar.opened.store'), ['date' => '2026-09-19', 'scope_type' => 'team', 'scope_id' => $this->team->id])
        ->assertSessionHasErrors('date');
});

it('opens a team when only some members already have the date as a workday', function () {
    OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::User, 'scope_id' => $this->member->id, 'opened_by' => $this->lead->id]);

    $this->actingAs($this->lead)
        ->post(route('calendar.opened.store'), ['date' => '2026-09-19', 'scope_type' => 'team', 'scope_id' => $this->team->id])
        ->assertSessionHasNoErrors();

    expect(OpenedWorkday::query()->count())->toBe(2);
});

it('opens a public holiday that falls on a weekday', function () {
    CalendarDay::query()->create(['date' => '2026-09-16', 'type' => CalendarDayType::Holiday, 'name' => 'Libur contoh', 'created_by' => userWithRole(Role::Superadmin)->id]);

    $this->actingAs($this->lead)
        ->post(route('calendar.opened.store'), ['date' => '2026-09-16', 'scope_type' => 'team', 'scope_id' => $this->team->id])
        ->assertSessionHasNoErrors();

    expect(OpenedWorkday::query()->count())->toBe(1);
});

it('refuses a studio-wide workday entry on a Saturday', function () {
    CalendarDay::query()->create(['date' => '2026-09-19', 'type' => CalendarDayType::Workday, 'name' => 'Ganti libur', 'created_by' => userWithRole(Role::Superadmin)->id]);
    $emptyTeam = Team::factory()->create(['lead_user_id' => $this->lead->id]);

    $this->actingAs($this->lead)
        ->post(route('calendar.opened.store'), ['date' => '2026-09-19', 'scope_type' => 'team', 'scope_id' => $emptyTeam->id])
        ->assertSessionHasErrors('date');
});

it('refuses a date that is already open for the same scope', function () {
    OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::User, 'scope_id' => $this->member->id, 'opened_by' => $this->lead->id]);

    $this->actingAs(userWithRole(Role::ProjectManager))
        ->post(route('calendar.opened.store'), ['date' => '2026-09-19', 'scope_type' => 'user', 'scope_id' => $this->member->id])
        ->assertSessionHasErrors(['date' => __('calendar::messages.already_opened', ['date' => 'Sabtu, 19 September 2026', 'scope' => $this->member->name])]);
});

it('closes an opened date with a soft delete, an audit row, and an event', function () {
    $opened = OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::Team, 'scope_id' => $this->team->id, 'opened_by' => $this->lead->id, 'note' => 'Kejar render']);

    $this->actingAs($this->lead)
        ->delete(route('calendar.opened.destroy', $opened))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    expect(OpenedWorkday::query()->count())->toBe(0)
        ->and(OpenedWorkday::withTrashed()->find($opened->id)->trashed())->toBeTrue()
        ->and(app(WorkdayResolver::class)->isWorkday($this->member, '2026-09-19'))->toBeFalse();

    $log = AuditLog::query()->where('action', 'calendar.opened_workday.closed')->sole();
    expect($log->before)->toMatchArray(['date' => '2026-09-19', 'scope_type' => 'team', 'note' => 'Kejar render'])
        ->and($log->after)->toBeNull();

    $memberIds = collect([$this->member->id, $this->otherMember->id])->sort()->values()->all();
    Event::assertDispatched(CalendarDatesChanged::class, fn (CalendarDatesChanged $e) => $e->dates === ['2026-09-19'] && $e->userIds === $memberIds);
});

it('restores the closed row when the same date and scope is opened again', function () {
    $pm = userWithRole(Role::ProjectManager);
    $opened = OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::User, 'scope_id' => $this->member->id, 'opened_by' => $pm->id, 'note' => 'Pertama']);
    $opened->delete();

    $this->actingAs($this->lead)
        ->post(route('calendar.opened.store'), ['date' => '2026-09-19', 'scope_type' => 'user', 'scope_id' => $this->member->id, 'note' => 'Kedua'])
        ->assertSessionHasNoErrors();

    expect(OpenedWorkday::withTrashed()->count())->toBe(1);

    $restored = OpenedWorkday::query()->sole();
    expect($restored)
        ->id->toBe($opened->id)
        ->opened_by->toBe($this->lead->id)
        ->note->toBe('Kedua');

    $log = AuditLog::query()->where('action', 'calendar.opened_workday.reopened')->sole();
    expect($log->subject_id)->toBe($opened->id)
        ->and($log->before)->toMatchArray(['note' => 'Pertama', 'opened_by' => $pm->id])
        ->and($log->before['closed_at'])->not->toBeNull()
        ->and($log->after)->toMatchArray(['note' => 'Kedua', 'opened_by' => $this->lead->id]);
});

it('refuses to close a date opened for a team the Team Lead does not lead', function () {
    $opened = OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::Team, 'scope_id' => $this->otherTeam->id, 'opened_by' => userWithRole(Role::ProjectDirector)->id]);

    $this->actingAs($this->lead)
        ->delete(route('calendar.opened.destroy', $opened))
        ->assertForbidden();

    expect($opened->fresh()->trashed())->toBeFalse();
    Event::assertNotDispatched(CalendarDatesChanged::class);
});

it('lets a Team Lead close a date another person opened for their team', function () {
    $opened = OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::Team, 'scope_id' => $this->team->id, 'opened_by' => userWithRole(Role::ProjectDirector)->id]);

    $this->actingAs($this->lead)
        ->delete(route('calendar.opened.destroy', $opened))
        ->assertSessionHasNoErrors();

    expect($opened->fresh()->trashed())->toBeTrue();
});

it('validates the opening request', function (array $payload, array $errors) {
    // 'TEAM' stands for the id of the team created in beforeEach.
    $payload = array_map(fn ($value) => $value === 'TEAM' ? $this->team->id : $value, $payload);

    $this->actingAs(userWithRole(Role::ProjectManager))
        ->post(route('calendar.opened.store'), $payload)
        ->assertSessionHasErrors($errors);

    expect(OpenedWorkday::query()->count())->toBe(0);
})->with([
    'empty' => [[], ['date', 'scope_type', 'scope_id']],
    'bad date' => [['date' => '19-09-2026', 'scope_type' => 'team', 'scope_id' => 'TEAM'], ['date']],
    'unknown scope' => [['date' => '2026-09-19', 'scope_type' => 'project', 'scope_id' => 'TEAM'], ['scope_type']],
    'missing team' => [['date' => '2026-09-19', 'scope_type' => 'team', 'scope_id' => 999999], ['scope_id']],
    'missing person' => [['date' => '2026-09-19', 'scope_type' => 'user', 'scope_id' => 999999], ['scope_id']],
    'note too long' => [['date' => '2026-09-19', 'scope_type' => 'team', 'scope_id' => 'TEAM', 'note' => str_repeat('a', 256)], ['note']],
]);

it('refuses to open a date for a suspended person', function () {
    $suspended = User::factory()->suspended()->create();

    $this->actingAs(userWithRole(Role::ProjectManager))
        ->post(route('calendar.opened.store'), ['date' => '2026-09-19', 'scope_type' => 'user', 'scope_id' => $suspended->id])
        ->assertSessionHasErrors('scope_id');
});

it('allows opening a date in the past', function () {
    $this->actingAs($this->lead)
        ->post(route('calendar.opened.store'), ['date' => '2026-09-12', 'scope_type' => 'team', 'scope_id' => $this->team->id])
        ->assertSessionHasNoErrors();

    expect(OpenedWorkday::query()->count())->toBe(1);
});
