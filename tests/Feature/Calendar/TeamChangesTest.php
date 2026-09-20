<?php

use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Calendar\Events\CalendarDatesChanged;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Calendar\Services\WorkdayResolver;
use App\Modules\Identity\Access\Role;
use App\Modules\Organization\Models\Team;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->admin = userWithRole(Role::Superadmin);
    $this->person = userWithRole(Role::Employee);
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->person);

    // 2026-09-19 is a Saturday.
    OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::Team, 'scope_id' => $this->team->id, 'opened_by' => $this->admin->id]);
});

it('closes the opened days of a deleted team and tells attendance which people changed', function () {
    Event::fake([CalendarDatesChanged::class]);

    $this->actingAs($this->admin)->delete(route('admin.teams.destroy', $this->team))->assertRedirect();

    expect(OpenedWorkday::query()->count())->toBe(0)
        ->and(OpenedWorkday::withTrashed()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'calendar.opened_workday.closed_with_team')->exists())->toBeTrue()
        ->and(app(WorkdayResolver::class)->isWorkday($this->person->refresh(), '2026-09-19'))->toBeFalse();

    Event::assertDispatched(CalendarDatesChanged::class, fn ($e) => $e->dates === ['2026-09-19'] && $e->userIds === [$this->person->id]);
});

it('recalculates opened dates for someone who leaves the team', function () {
    Event::fake([CalendarDatesChanged::class]);

    $this->actingAs($this->admin)->delete(route('admin.teams.members.destroy', [$this->team, $this->person]))->assertRedirect();

    expect(OpenedWorkday::query()->count())->toBe(1);
    Event::assertDispatched(CalendarDatesChanged::class, fn ($e) => $e->dates === ['2026-09-19'] && $e->userIds === [$this->person->id]);
});
