<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

describe('authorization', function () {
    it('lets a Superadmin open the teams page and shows it in the menu', function () {
        $admin = userWithRole(Role::Superadmin);

        $this->actingAs($admin)->get(route('admin.teams.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/teams/Index')
                ->where('nav', fn ($nav) => collect($nav)->firstWhere('group', 'people')['items'][1]['key'] === 'teams'));
    });

    it('refuses employees and management on every team route', function (Role $role) {
        $user = userWithRole($role);
        $team = Team::factory()->create();
        $member = userWithRole(Role::Employee);
        $team->members()->attach($member);

        $this->actingAs($user)->get(route('admin.teams.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.teams.store'), ['name' => 'Baru'])->assertForbidden();
        $this->actingAs($user)->put(route('admin.teams.update', $team), ['name' => 'Ganti', 'lead_user_id' => null])->assertForbidden();
        $this->actingAs($user)->delete(route('admin.teams.destroy', $team))->assertForbidden();
        $this->actingAs($user)->post(route('admin.teams.members.store', $team), ['user_id' => $user->id])->assertForbidden();
        $this->actingAs($user)->delete(route('admin.teams.members.destroy', [$team, $member]))->assertForbidden();

        expect(Team::query()->count())->toBe(1)->and($team->members()->count())->toBe(1);
    })->with([
        'employee' => [Role::Employee],
        'team lead' => [Role::TeamLead],
        'project manager' => [Role::ProjectManager],
        'project director' => [Role::ProjectDirector],
    ]);
});

it('lists teams with lead, members, and who can lead', function () {
    $admin = userWithRole(Role::Superadmin);
    $lead = userWithRole(Role::Employee, Role::TeamLead);
    $member = userWithRole(Role::Employee);
    $team = Team::factory()->create(['name' => 'Animation', 'lead_user_id' => $lead->id]);
    $team->members()->attach([$lead->id, $member->id]);
    Team::factory()->create(['name' => 'Lighting']);

    $this->actingAs($admin)->get(route('admin.teams.index'))
        ->assertInertia(fn ($page) => $page
            ->has('teams', 2)
            ->where('teams.0.name', 'Animation')
            ->where('teams.0.lead_user_id', $lead->id)
            ->has('teams.0.members', 2)
            ->where('teams.0.members', fn ($members) => collect($members)->firstWhere('id', $lead->id)['can_lead'] === true
                && collect($members)->firstWhere('id', $member->id)['can_lead'] === false)
            ->where('teams.1.name', 'Lighting')
            ->has('teams.1.members', 0));
});

it('creates and renames a team, with unique names, and audits both', function () {
    $admin = userWithRole(Role::Superadmin);

    $this->actingAs($admin)->post(route('admin.teams.store'), ['name' => 'Modeling'])->assertSessionHasNoErrors();
    $team = Team::query()->where('name', 'Modeling')->sole();

    $this->actingAs($admin)->post(route('admin.teams.store'), ['name' => 'Modeling'])->assertSessionHasErrors('name');
    $this->actingAs($admin)->post(route('admin.teams.store'), ['name' => ''])->assertSessionHasErrors('name');

    $this->actingAs($admin)->put(route('admin.teams.update', $team), ['name' => 'Modeling 3D', 'lead_user_id' => null])
        ->assertSessionHasNoErrors();

    expect($team->refresh()->name)->toBe('Modeling 3D')
        ->and(AuditLog::query()->where('action', 'team.created')->sole()->after)->toBe(['name' => 'Modeling'])
        ->and(AuditLog::query()->where('action', 'team.renamed')->sole()->after)->toBe(['name' => 'Modeling 3D']);
});

describe('Team Lead', function () {
    it('sets a member holding the team_lead role as lead and audits it', function () {
        $admin = userWithRole(Role::Superadmin);
        $lead = userWithRole(Role::Employee, Role::TeamLead);
        $team = Team::factory()->create();
        $team->members()->attach($lead);

        $this->actingAs($admin)->put(route('admin.teams.update', $team), ['name' => $team->name, 'lead_user_id' => $lead->id])
            ->assertSessionHasNoErrors();

        expect($team->refresh()->lead_user_id)->toBe($lead->id);

        $log = AuditLog::query()->where('action', 'team.lead_changed')->sole();
        expect($log->before)->toBe(['lead_user_id' => null])->and($log->after)->toBe(['lead_user_id' => $lead->id]);
    });

    it('refuses a lead who is not a member', function () {
        $admin = userWithRole(Role::Superadmin);
        $outsider = userWithRole(Role::TeamLead);
        $team = Team::factory()->create();

        $this->actingAs($admin)->put(route('admin.teams.update', $team), ['name' => $team->name, 'lead_user_id' => $outsider->id])
            ->assertSessionHasErrors('lead_user_id');

        expect($team->refresh()->lead_user_id)->toBeNull();
    });

    it('refuses a member without the team_lead role', function () {
        $admin = userWithRole(Role::Superadmin);
        $member = userWithRole(Role::Employee, Role::ProjectManager);
        $team = Team::factory()->create();
        $team->members()->attach($member);

        $this->actingAs($admin)->put(route('admin.teams.update', $team), ['name' => $team->name, 'lead_user_id' => $member->id])
            ->assertSessionHasErrors('lead_user_id');
    });

    it('removes the lead when the lead is removed from the team', function () {
        $admin = userWithRole(Role::Superadmin);
        $lead = userWithRole(Role::TeamLead);
        $team = Team::factory()->create(['lead_user_id' => $lead->id]);
        $team->members()->attach($lead);

        $this->actingAs($admin)->delete(route('admin.teams.members.destroy', [$team, $lead]))->assertSessionHasNoErrors();

        expect($team->refresh()->lead_user_id)->toBeNull()
            ->and($team->members()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'team.lead_changed')->sole()->after)->toEqual(['lead_user_id' => null, 'reason' => 'member_removed']);
    });
});

describe('members', function () {
    it('adds and removes members and audits both', function () {
        $admin = userWithRole(Role::Superadmin);
        $person = userWithRole(Role::Employee);
        $team = Team::factory()->create();

        $this->actingAs($admin)->post(route('admin.teams.members.store', $team), ['user_id' => $person->id])->assertSessionHasNoErrors();
        expect($team->members()->pluck('users.id')->all())->toBe([$person->id])
            ->and($team->members()->first()->pivot->joined_at)->not->toBeNull();

        $this->actingAs($admin)->post(route('admin.teams.members.store', $team), ['user_id' => $person->id])->assertSessionHasErrors('user_id');
        $this->actingAs($admin)->post(route('admin.teams.members.store', $team), ['user_id' => 999999])->assertSessionHasErrors('user_id');

        $this->actingAs($admin)->delete(route('admin.teams.members.destroy', [$team, $person]))->assertSessionHasNoErrors();
        expect($team->members()->count())->toBe(0);

        expect(AuditLog::query()->where('action', 'team.member_added')->sole()->after)->toBe(['user_id' => $person->id])
            ->and(AuditLog::query()->where('action', 'team.member_removed')->sole()->before)->toBe(['user_id' => $person->id]);
    });

    it('returns 404 when removing someone who is not a member', function () {
        $admin = userWithRole(Role::Superadmin);
        $person = userWithRole(Role::Employee);
        $team = Team::factory()->create();

        $this->actingAs($admin)->delete(route('admin.teams.members.destroy', [$team, $person]))->assertNotFound();
    });

    it('shows the same membership on the people page after a change on the team page', function () {
        $admin = userWithRole(Role::Superadmin);
        $person = userWithRole(Role::Employee);
        $team = Team::factory()->create();

        $this->actingAs($admin)->post(route('admin.teams.members.store', $team), ['user_id' => $person->id]);

        $this->actingAs($admin)->get(route('admin.people.index', ['team' => $team->id]))
            ->assertInertia(fn ($page) => $page->has('people.data', 1)->where('people.data.0.id', $person->id));
    });
});

it('deletes a team, detaches members, keeps their accounts, and audits it', function () {
    $admin = userWithRole(Role::Superadmin);
    $lead = userWithRole(Role::TeamLead);
    $member = userWithRole(Role::Employee);
    $team = Team::factory()->create(['name' => 'Rigging', 'lead_user_id' => $lead->id]);
    $team->members()->attach([$lead->id, $member->id]);

    $this->actingAs($admin)->delete(route('admin.teams.destroy', $team))->assertSessionHasNoErrors()->assertRedirect();

    expect(Team::query()->find($team->id))->toBeNull()
        ->and(User::query()->whereKey([$lead->id, $member->id])->count())->toBe(2)
        ->and(DB::table('team_user')->where('team_id', $team->id)->count())->toBe(0);

    $log = AuditLog::query()->where('action', 'team.deleted')->sole();
    expect($log->before['name'])->toBe('Rigging')
        ->and($log->before['member_ids'])->toBe(collect([$lead->id, $member->id])->sort()->values()->all());
});
