<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Organization\Models\Team;
use Tests\Feature\Attendance\Support\Desk;
use Tests\TestCase;

beforeEach(function () {
    $this->travelTo(Desk::time('2026-09-20 10:00'));

    $this->animLead = userWithRole(Role::TeamLead);
    $this->lightLead = userWithRole(Role::TeamLead);
    $this->animator = userWithRole(Role::Employee);
    $this->lighter = userWithRole(Role::Employee);
    $this->loner = userWithRole(Role::Employee);
    $this->manager = userWithRole(Role::ProjectManager);
    $this->director = userWithRole(Role::ProjectDirector);
    $this->admin = userWithRole(Role::Superadmin);

    $this->animation = Team::factory()->create(['name' => 'Animation', 'lead_user_id' => $this->animLead->id]);
    $this->animation->members()->attach([$this->animator->id, $this->animLead->id]);
    $this->lighting = Team::factory()->create(['name' => 'Lighting', 'lead_user_id' => $this->lightLead->id]);
    $this->lighting->members()->attach([$this->lighter->id, $this->lightLead->id]);
});

/** @return array{groups: list<array{team: string|null, ids: list<int>}>, total_people: int, is_studio: bool, shared: bool} */
function reportPageGroups(TestCase $test, $viewer, array $query = []): array
{
    $report = $test->actingAs($viewer)->get(route('reports.index', $query))->assertOk()->viewData('page')['props']['report'];

    return [
        'groups' => array_map(fn ($g) => ['team' => $g['team']['name'] ?? null, 'ids' => collect($g['people'])->pluck('id')->sort()->values()->all()], $report['groups']),
        'total_people' => $report['total']['people'],
        'is_studio' => $report['is_studio'],
        'shared' => $report['shared_people'],
    ];
}

function reportSortedIds(...$users): array
{
    return collect($users)->pluck('id')->sort()->values()->all();
}

it('refuses people without a report permission', function () {
    $this->actingAs($this->animator)->get(route('reports.index'))->assertForbidden();
});

it('sends guests to sign in', function () {
    $this->get(route('reports.index'))->assertRedirect(route('sign-in'));
    $this->get(route('reports.export'))->assertRedirect(route('sign-in'));
});

it('shows a Team Lead only the members of the teams they lead', function () {
    $page = reportPageGroups($this, $this->animLead);

    expect($page['groups'])->toBe([['team' => 'Animation', 'ids' => reportSortedIds($this->animator, $this->animLead)]])
        ->and($page['is_studio'])->toBeFalse();

    $this->actingAs($this->animLead)->get(route('reports.index'))
        ->assertInertia(fn ($page) => $page
            ->where('scope', 'led_teams')
            ->where('teams', [['id' => $this->animation->id, 'name' => 'Animation']])
            ->where('can.export', false));
});

it('shows a Team Lead who leads no team an empty report', function () {
    $lead = userWithRole(Role::TeamLead);

    expect(reportPageGroups($this, $lead)['groups'])->toBe([]);
});

it('shows Project Managers, Project Directors and Superadmin everyone, grouped by team', function (string $viewer) {
    $page = reportPageGroups($this, $this->{$viewer});
    $everyone = reportSortedIds($this->animLead, $this->lightLead, $this->animator, $this->lighter, $this->loner, $this->manager, $this->director, $this->admin);

    expect(collect($page['groups'])->pluck('team')->all())->toBe(['Animation', 'Lighting', null])
        ->and($page['groups'][0]['ids'])->toBe(reportSortedIds($this->animator, $this->animLead))
        ->and($page['groups'][1]['ids'])->toBe(reportSortedIds($this->lighter, $this->lightLead))
        ->and($page['groups'][2]['ids'])->toBe(reportSortedIds($this->loner, $this->manager, $this->director, $this->admin))
        ->and($page['total_people'])->toBe(count($everyone))
        ->and($page['is_studio'])->toBeTrue();
})->with(['manager', 'director', 'admin']);

it('counts a person in two teams under both teams but once in the total', function () {
    $this->lighting->members()->attach($this->animator);

    $page = reportPageGroups($this, $this->manager);

    expect($page['groups'][0]['ids'])->toContain($this->animator->id)
        ->and($page['groups'][1]['ids'])->toContain($this->animator->id)
        ->and($page['total_people'])->toBe(8)
        ->and($page['shared'])->toBeTrue();
});

it('filters by team, and ignores a team outside the viewer\'s scope', function () {
    $filtered = reportPageGroups($this, $this->director, ['tim' => $this->lighting->id]);

    expect($filtered['groups'])->toBe([['team' => 'Lighting', 'ids' => reportSortedIds($this->lighter, $this->lightLead)]])
        ->and($filtered['is_studio'])->toBeFalse();

    $outside = reportPageGroups($this, $this->animLead, ['tim' => $this->lighting->id]);

    expect($outside['groups'])->toBe([['team' => 'Animation', 'ids' => reportSortedIds($this->animator, $this->animLead)]]);

    $this->actingAs($this->animLead)->get(route('reports.index', ['tim' => $this->lighting->id]))
        ->assertInertia(fn ($page) => $page->where('filters.team', null));
});

it('opens a per-day breakdown only for people inside the viewer\'s scope', function () {
    $this->actingAs($this->animLead)->get(route('reports.index', ['orang' => $this->animator->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('detail.person.id', $this->animator->id)->where('detail.shifts', []));

    $this->actingAs($this->animLead)->get(route('reports.index', ['orang' => $this->lighter->id]))->assertNotFound();
    $this->actingAs($this->manager)->get(route('reports.index', ['orang' => $this->lighter->id]))->assertOk();
    $this->actingAs($this->manager)->get(route('reports.index', ['orang' => 999999]))->assertNotFound();
});

it('falls back to the current month for a malformed month', function () {
    $this->actingAs($this->manager)->get(route('reports.index', ['bulan' => '2026-13']))
        ->assertInertia(fn ($page) => $page->where('month.value', '2026-09'));
});
