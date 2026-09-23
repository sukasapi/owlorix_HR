<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use Tests\Feature\Attendance\Support\Desk;
use Tests\TestCase;

// Monday 2026-09-14, Asia/Jakarta.

function teamTodayPerson(string $name, Role $role = Role::Employee): User
{
    return User::factory()->withRole($role)->create(['name' => $name]);
}

/** @return list<array<string, mixed>> */
function teamTodayPeople(TestCase $test, User $viewer): array
{
    return $test->actingAs($viewer)->get(route('team.today'))->assertOk()->viewData('page')['props']['board']['people'];
}

beforeEach(function () {
    $this->lead = teamTodayPerson('Lead Animation', Role::TeamLead);
    $this->manager = teamTodayPerson('Manager Satu', Role::ProjectManager);
    $this->director = teamTodayPerson('Director Satu', Role::ProjectDirector);

    $this->team = Team::factory()->create(['name' => 'Animation', 'lead_user_id' => $this->lead->id]);
});

test('a Team Lead sees members of the teams they lead, not themselves or other teams', function () {
    $member = teamTodayPerson('Anggota Animation');
    $outsider = teamTodayPerson('Anggota Lighting');
    $this->team->members()->attach([$this->lead->id, $member->id]);
    Team::factory()->create(['name' => 'Lighting'])->members()->attach($outsider);

    $this->actingAs($this->lead)->get(route('team.today'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('team-today/Index')
            ->where('scope', ['everyone' => false, 'leads_team' => true])
            ->has('board.people', 1)
            ->where('board.people.0.id', $member->id)
            ->where('board.people.0.teams', ['Animation'])
            ->where('board.teams', [['id' => $this->team->id, 'name' => 'Animation']]));
});

test('Project Managers and Project Directors see every active person except themselves', function () {
    $member = teamTodayPerson('Anggota Animation');
    $noTeam = teamTodayPerson('Tanpa Tim');
    $suspended = teamTodayPerson('Dinonaktifkan');
    $suspended->forceFill(['status' => UserStatus::Suspended])->save();
    $this->team->members()->attach($member);

    $ids = fn (User $viewer) => collect(teamTodayPeople($this, $viewer))->pluck('id')->sort()->values()->all();

    expect($ids($this->manager))->toBe(collect([$this->lead->id, $this->director->id, $member->id, $noTeam->id])->sort()->values()->all())
        ->and($ids($this->director))->toBe(collect([$this->lead->id, $this->manager->id, $member->id, $noTeam->id])->sort()->values()->all());
});

test('a Team Lead who leads no team gets an empty board with the reason', function () {
    $lonely = teamTodayPerson('Lead Tanpa Tim', Role::TeamLead);
    teamTodayPerson('Orang Lain');

    $this->actingAs($lonely)->get(route('team.today'))
        ->assertInertia(fn ($page) => $page
            ->where('scope', ['everyone' => false, 'leads_team' => false])
            ->has('board.people', 0)
            ->has('board.teams', 0));
});

test('employees and Superadmin cannot open the team board', function () {
    $this->actingAs(teamTodayPerson('Karyawan'))->get(route('team.today'))->assertForbidden();
    $this->actingAs(teamTodayPerson('Admin', Role::Superadmin))->get(route('team.today'))->assertForbidden();
});

test('4.2.6 the board shows each person\'s state right now, ordered by status group then name', function () {
    $prompted = teamTodayPerson('Bayu Prompt');
    $reportDue = teamTodayPerson('Ayu Laporan');
    $overtime = teamTodayPerson('Citra Lembur');
    $working = teamTodayPerson('Dimas Masuk');
    $idle = teamTodayPerson('Eka Diam');
    $out = teamTodayPerson('Fajar Pulang');
    $notStarted = teamTodayPerson('Gita Belum');
    $this->team->members()->attach([$prompted->id, $reportDue->id, $overtime->id, $working->id, $idle->id, $out->id, $notStarted->id]);

    $r = Desk::for($this, $reportDue, 'PC-ANIM-01');
    $o = Desk::for($this, $overtime, 'PC-ANIM-02');
    $p = Desk::for($this, $prompted, 'PC-ANIM-03');
    $x = Desk::for($this, $out, 'PC-ANIM-04');
    $w = Desk::for($this, $working, 'PC-ANIM-05');
    $i = Desk::for($this, $idle, 'PC-ANIM-06');

    $r->send('clock_in', at: '2026-09-14 06:00');
    $o->send('clock_in', at: '07:00');
    $p->send('clock_in', at: '08:00');
    $x->send('clock_in', at: '08:30');
    $w->send('clock_in', at: '09:00');
    $i->send('clock_in', at: '09:00');
    $x->send('clock_out', at: '12:00');
    $r->send('overtime_start', ['reason' => 'Render final shot 12'], '14:02');
    $r->send('clock_out', at: '15:00');
    $o->send('overtime_start', ['reason' => 'Revisi lighting shot 3'], '15:03');
    $i->at('15:12');
    $i->sync([$i->event('idle_start', at: '15:00')])->assertOk();
    foreach ([$o, $p, $w, $i] as $desk) {
        $desk->heartbeat('16:04');
    }
    $this->travelTo(Desk::time('16:05'));

    $people = collect(teamTodayPeople($this, $this->lead))->keyBy('id');

    expect(collect(teamTodayPeople($this, $this->lead))->pluck('name')->all())
        ->toBe(['Ayu Laporan', 'Bayu Prompt', 'Citra Lembur', 'Dimas Masuk', 'Eka Diam', 'Fajar Pulang', 'Gita Belum'])
        ->and($people[$reportDue->id])->toMatchArray(['group' => 'attention', 'status' => 'report_due', 'eyes' => 'attention', 'since' => '2026-09-14T08:00:00.000Z', 'overtime_minutes' => 60])
        ->and($people[$prompted->id])->toMatchArray(['group' => 'attention', 'status' => 'prompted', 'eyes' => 'attention', 'since' => '2026-09-14T09:00:00.000Z', 'regular_minutes' => 480, 'device' => 'PC-ANIM-03'])
        ->and($people[$overtime->id])->toMatchArray(['group' => 'overtime', 'status' => 'overtime', 'eyes' => 'open', 'since' => '2026-09-14T08:00:00.000Z', 'regular_minutes' => 480, 'overtime_minutes' => 65, 'idle' => null])
        ->and($people[$working->id])->toMatchArray(['group' => 'working', 'status' => 'open', 'eyes' => 'open', 'since' => '2026-09-14T02:00:00.000Z', 'regular_minutes' => 425, 'device' => 'PC-ANIM-05'])
        ->and($people[$idle->id])->toMatchArray(['group' => 'idle', 'status' => 'idle', 'eyes' => 'half', 'since' => '2026-09-14T08:00:00.000Z'])
        ->and($people[$idle->id]['idle'])->toBe(['started_at' => '2026-09-14T08:00:00.000Z', 'minutes' => 65, 'tag' => null])
        ->and($people[$out->id])->toMatchArray(['group' => 'out', 'status' => 'out', 'eyes' => 'closed', 'since' => '2026-09-14T05:00:00.000Z', 'regular_minutes' => 210, 'device' => null])
        ->and($people[$notStarted->id])->toMatchArray(['group' => 'not_started', 'status' => 'not_started', 'eyes' => 'closed', 'since' => null, 'regular_minutes' => 0, 'needs_review' => false]);
});

test('quiet PC during overtime keeps the person in overtime with half-closed eyes', function () {
    $person = teamTodayPerson('Citra Lembur');
    $this->team->members()->attach($person);
    $desk = Desk::for($this, $person);

    $desk->send('clock_in', at: '2026-09-14 08:00');
    $desk->send('overtime_start', ['reason' => 'Render final shot 12'], '16:02');
    $desk->at('16:40');
    $desk->sync([$desk->event('idle_start', at: '16:30')])->assertOk();
    $desk->heartbeat('16:44');
    $this->travelTo(Desk::time('16:45'));

    expect(teamTodayPeople($this, $this->lead)[0])
        ->toMatchArray(['group' => 'overtime', 'status' => 'overtime', 'eyes' => 'half'])
        ->and(teamTodayPeople($this, $this->lead)[0]['idle']['minutes'])->toBe(15);
});

test('a PC that stopped reporting shows as interrupted in the quiet group', function () {
    $person = teamTodayPerson('Hana Putus');
    $this->team->members()->attach($person);
    $desk = Desk::for($this, $person);

    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->send('pc_shutdown', at: '12:00');
    $this->travelTo(Desk::time('12:10'));

    expect(teamTodayPeople($this, $this->lead)[0])
        ->toMatchArray(['group' => 'idle', 'status' => 'interrupted', 'eyes' => 'half']);
});

test('a shift closed for review today is flagged on the board', function () {
    $person = teamTodayPerson('Indra Dicek');
    $this->team->members()->attach($person);
    $desk = Desk::for($this, $person);

    $desk->send('clock_in', at: '2026-09-14 08:00');
    $desk->heartbeat('09:58');
    $desk->send('pc_shutdown', at: '10:00');
    $desk->send('clock_in', at: '11:40');
    $this->travelTo(Desk::time('11:41'));

    expect(teamTodayPeople($this, $this->lead)[0])
        ->toMatchArray(['group' => 'working', 'needs_review' => true]);
});

test('a shift still running from yesterday counts as clocked in today', function () {
    $person = teamTodayPerson('Joko Lembur Malam');
    $this->team->members()->attach($person);
    $desk = Desk::for($this, $person);

    $desk->send('clock_in', ['reason' => 'Render malam untuk klien'], '2026-09-13 22:00');
    $desk->heartbeat('2026-09-14 00:29');
    $this->travelTo(Desk::time('2026-09-14 00:30'));

    expect(teamTodayPeople($this, $this->lead)[0])->toMatchArray(['group' => 'overtime', 'status' => 'overtime', 'regular_minutes' => 0]);
});

test('polling reloads only the board prop with a fresh update time', function () {
    $member = teamTodayPerson('Anggota Animation');
    $this->team->members()->attach($member);
    $this->travelTo(Desk::time('2026-09-14 10:00'));

    $version = $this->actingAs($this->lead)->get(route('team.today'))->viewData('page')['version'];
    $this->travelTo(Desk::time('2026-09-14 10:00:30'));

    $response = $this->actingAs($this->lead)->get(route('team.today'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'team-today/Index',
        'X-Inertia-Partial-Data' => 'board',
    ])->assertOk();

    expect(array_keys($response->json('props')))->not->toContain('scope')
        ->and($response->json('props.board.generated_at'))->toBe('2026-09-14T03:00:30.000Z')
        ->and($response->json('props.board.date'))->toBe('2026-09-14')
        ->and(array_keys($response->json('props.board.people.0')))->toBe([
            'id', 'name', 'initials', 'team_ids', 'teams', 'group', 'status', 'eyes', 'since', 'device',
            'regular_minutes', 'overtime_minutes', 'idle', 'needs_review', 'leave',
        ]);
});

test('the Tim hari ini menu item appears for Management once the route exists', function () {
    $this->actingAs($this->lead)->get(route('my-day'))
        ->assertInertia(fn ($page) => $page->where('nav.1.items', fn ($items) => collect($items)->contains('key', 'team_today')));
});
