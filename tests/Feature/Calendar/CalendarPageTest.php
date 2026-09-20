<?php

use App\Modules\Calendar\Enums\CalendarDayType;
use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // 14.00 WIB on Monday 2026-09-14.
    $this->travelTo(CarbonImmutable::parse('2026-09-14 07:00:00', 'UTC'));
});

it('lets Superadmin and every Management role open the calendar', function (Role $role) {
    $this->actingAs(userWithRole($role))
        ->get(route('calendar.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('calendar/Index'));
})->with([Role::Superadmin, Role::TeamLead, Role::ProjectManager, Role::ProjectDirector]);

it('refuses the calendar to an employee', function () {
    $this->actingAs(userWithRole(Role::Employee))
        ->get(route('calendar.index'))
        ->assertForbidden();
});

it('sends a guest to sign in', function () {
    $this->get(route('calendar.index'))->assertRedirect(route('sign-in'));
});

it('shows the calendar in the navigation for Management', function () {
    $this->actingAs(userWithRole(Role::TeamLead))
        ->get(route('calendar.index'))
        ->assertInertia(fn ($page) => $page->where('nav', fn ($nav) => collect($nav)
            ->flatMap(fn ($group) => $group['items'])
            ->contains(fn ($item) => $item['key'] === 'calendar' && $item['href'] === '/kalender')));
});

it('uses the current studio month by default and today in Asia/Jakarta', function () {
    // 18.30 UTC on the 30th is already 1 October 01.30 in Jakarta.
    $this->travelTo(CarbonImmutable::parse('2026-09-30 18:30:00', 'UTC'));

    $this->actingAs(userWithRole(Role::Superadmin))
        ->get(route('calendar.index'))
        ->assertInertia(fn ($page) => $page
            ->where('month.value', '2026-10')
            ->where('month.previous', '2026-09')
            ->where('month.next', '2026-11')
            ->where('month.current', '2026-10')
            ->where('today', '2026-10-01')
            ->where('days.0.is_today', true));
});

it('falls back to the current month when the month parameter is not YYYY-MM', function (string $value) {
    $this->actingAs(userWithRole(Role::Superadmin))
        ->get(route('calendar.index', ['bulan' => $value]))
        ->assertInertia(fn ($page) => $page->where('month.value', '2026-09'));
})->with(['2026-13', 'september', '2026-9', '']);

it('returns every date of the requested month with its workday status, entry, and opened scopes', function () {
    $admin = userWithRole(Role::Superadmin);
    $lead = userWithRole(Role::TeamLead);
    $team = Team::factory()->create(['name' => 'Animation', 'lead_user_id' => $lead->id]);

    CalendarDay::query()->create(['date' => '2026-02-17', 'type' => CalendarDayType::Holiday, 'name' => 'Libur contoh', 'created_by' => $admin->id]);
    OpenedWorkday::query()->create(['date' => '2026-02-07', 'scope_type' => OpenedScope::Team, 'scope_id' => $team->id, 'opened_by' => $lead->id, 'note' => 'Kejar render']);

    $this->actingAs($admin)
        ->get(route('calendar.index', ['bulan' => '2026-02']))
        ->assertInertia(fn ($page) => $page
            ->where('month.value', '2026-02')
            ->where('month.previous', '2026-01')
            ->where('month.next', '2026-03')
            ->has('days', 28)
            ->where('days.0.date', '2026-02-01')
            ->where('days.0.weekday', 7)
            ->where('days.0.week_workday', false)
            ->where('days.0.is_past', true)
            ->where('days.1.date', '2026-02-02')
            ->where('days.1.is_studio_workday', true)
            ->where('days.16.date', '2026-02-17')
            ->where('days.16.week_workday', true)
            ->where('days.16.is_studio_workday', false)
            ->where('days.16.entry.type', 'holiday')
            ->where('days.16.entry.name', 'Libur contoh')
            ->where('days.6.date', '2026-02-07')
            ->has('days.6.opened', 1)
            ->where('days.6.opened.0.scope_type', 'team')
            ->where('days.6.opened.0.scope_name', 'Animation')
            ->where('days.6.opened.0.opened_by', $lead->name)
            ->where('days.6.opened.0.note', 'Kejar render')
            ->where('days.6.opened.0.can_close', true)
            ->has('work_week', 7)
            ->where('work_week.5', ['weekday' => 6, 'is_workday' => false])
            ->where('can.manage_calendar', true)
            ->where('can.open_workdays', true));
});

it('does not list a closed opened workday', function () {
    $lead = userWithRole(Role::TeamLead);
    $opened = OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::User, 'scope_id' => $lead->id, 'opened_by' => $lead->id]);
    $opened->delete();

    $this->actingAs(userWithRole(Role::ProjectManager))
        ->get(route('calendar.index', ['bulan' => '2026-09']))
        ->assertInertia(fn ($page) => $page->has('days.18.opened', 0));
});

it('lists only the teams and people a Team Lead may open days for', function () {
    $lead = userWithRole(Role::TeamLead);
    $member = userWithRole(Role::Employee);
    $suspended = User::factory()->suspended()->withRole(Role::Employee)->create();
    $outsider = userWithRole(Role::Employee);

    $ledTeam = Team::factory()->create(['name' => 'Lighting', 'lead_user_id' => $lead->id]);
    $ledTeam->members()->attach([$member->id, $suspended->id]);
    $otherTeam = Team::factory()->create(['name' => 'Modeling']);
    $otherTeam->members()->attach($outsider);

    $this->actingAs($lead)
        ->get(route('calendar.index'))
        ->assertInertia(fn ($page) => $page
            ->where('can.manage_calendar', false)
            ->where('can.open_workdays', true)
            ->has('scopes.teams', 1)
            ->where('scopes.teams.0', ['id' => $ledTeam->id, 'name' => 'Lighting', 'member_count' => 2])
            ->has('scopes.people', 1)
            ->where('scopes.people.0.id', $member->id));
});

it('lists every team and active person for Project Managers, Project Directors, and Superadmin', function (Role $role) {
    Team::factory()->count(2)->create();
    userWithRole(Role::Employee);
    User::factory()->suspended()->create();

    $viewer = userWithRole($role);
    $activeCount = User::query()->active()->count();

    $this->actingAs($viewer)
        ->get(route('calendar.index'))
        ->assertInertia(fn ($page) => $page
            ->has('scopes.teams', 2)
            ->has('scopes.people', $activeCount));
})->with([Role::ProjectManager, Role::ProjectDirector, Role::Superadmin]);

it('gives a Team Lead without a team nothing to open and marks rows of other teams as not closable', function () {
    $lead = userWithRole(Role::TeamLead);
    $pm = userWithRole(Role::ProjectManager);
    $team = Team::factory()->create();

    OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::Team, 'scope_id' => $team->id, 'opened_by' => $pm->id]);

    $this->actingAs($lead)
        ->get(route('calendar.index'))
        ->assertInertia(fn ($page) => $page
            ->has('scopes.teams', 0)
            ->has('scopes.people', 0)
            ->where('days.18.opened.0.can_close', false));
});
