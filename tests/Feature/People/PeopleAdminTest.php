<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function personPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Nama Uji',
        'username' => 'nama.uji',
        'email' => null,
        'employee_code' => null,
        'employment_type' => 'permanent',
        'roles' => [Role::Employee->value],
        'team_ids' => [],
    ], $overrides);
}

function updatePayload(User $user, array $overrides = []): array
{
    return array_merge([
        'name' => $user->name,
        'email' => $user->email,
        'employee_code' => $user->employee_code,
        'employment_type' => $user->employment_type->value,
        'roles' => $user->roles()->pluck('name')->all(),
        'team_ids' => $user->teams()->pluck('teams.id')->all(),
        'status' => $user->status->value,
    ], $overrides);
}

describe('authorization', function () {
    it('lets a Superadmin open the people page and shows it in the menu', function () {
        $admin = userWithRole(Role::Superadmin);

        $this->actingAs($admin)->get(route('admin.people.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/people/Index')
                ->where('nav', fn ($nav) => collect($nav)->firstWhere('group', 'people')['items'][0]['key'] === 'people'));
    });

    it('refuses employees and management everywhere on the people pages', function (Role $role) {
        $user = userWithRole($role);
        $other = userWithRole(Role::Employee);

        $this->actingAs($user)->get(route('admin.people.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.people.store'), personPayload())->assertForbidden();
        $this->actingAs($user)->put(route('admin.people.update', $other), updatePayload($other))->assertForbidden();
        $this->actingAs($user)->post(route('admin.people.reset-password', $other))->assertForbidden();

        expect(User::query()->where('username', 'nama.uji')->exists())->toBeFalse();
    })->with([
        'employee' => [Role::Employee],
        'team lead' => [Role::TeamLead],
        'project manager' => [Role::ProjectManager],
        'project director' => [Role::ProjectDirector],
    ]);

    it('sends guests to sign in', function () {
        $this->get(route('admin.people.index'))->assertRedirect(route('sign-in'));
    });
});

describe('list and filters', function () {
    it('shows active people by default, with roles and teams', function () {
        $admin = userWithRole(Role::Superadmin);
        $team = Team::factory()->create(['name' => 'Animation']);
        $active = userWithRole(Role::Employee, Role::TeamLead);
        $active->teams()->attach($team, ['joined_at' => '2026-09-01']);
        $suspended = User::factory()->suspended()->withRole(Role::Employee)->create();

        $this->actingAs($admin)->get(route('admin.people.index'))
            ->assertInertia(fn ($page) => $page
                ->where('filters.status', 'active')
                ->has('people.data', 2)
                ->where('people.data', fn ($rows) => collect($rows)->pluck('id')->doesntContain($suspended->id))
                ->where('people.data', fn ($rows) => collect($rows)->firstWhere('id', $active->id)['roles'] === ['employee', 'team_lead'])
                ->where('people.data', fn ($rows) => collect($rows)->firstWhere('id', $active->id)['team_ids'] === [$team->id]));
    });

    it('filters by status, team, and a search on name or username', function () {
        $admin = userWithRole(Role::Superadmin);
        $team = Team::factory()->create();
        $inTeam = User::factory()->withRole(Role::Employee)->create(['name' => 'Rani Anim', 'username' => 'rani.a']);
        $inTeam->teams()->attach($team);
        $noTeam = User::factory()->withRole(Role::Employee)->create(['name' => 'Bima Model', 'username' => 'bima.m']);
        $left = User::factory()->withRole(Role::Employee)->create(['status' => UserStatus::Left, 'name' => 'Dewi Rig']);

        $ids = fn ($query) => collect($this->actingAs($admin)->get(route('admin.people.index', $query))->viewData('page')['props']['people']['data'])->pluck('id')->all();

        expect($ids(['status' => 'left']))->toBe([$left->id])
            ->and($ids(['status' => 'all', 'team' => $team->id]))->toBe([$inTeam->id])
            ->and($ids(['team' => 'none']))->toContain($noTeam->id)->not->toContain($inTeam->id)
            ->and($ids(['q' => 'bima']))->toBe([$noTeam->id])
            ->and($ids(['q' => 'rani.a']))->toBe([$inTeam->id])
            ->and($ids(['q' => '%']))->toBe([]);
    });

    it('falls back to active when the status filter is unknown', function () {
        $admin = userWithRole(Role::Superadmin);

        $this->actingAs($admin)->get(route('admin.people.index', ['status' => 'deleted', 'team' => 'x']))
            ->assertInertia(fn ($page) => $page->where('filters.status', 'active')->where('filters.team', 'all'));
    });

    it('paginates 25 people per page', function () {
        $admin = userWithRole(Role::Superadmin);
        User::factory()->count(30)->withRole(Role::Employee)->create();

        $this->actingAs($admin)->get(route('admin.people.index', ['page' => 2]))
            ->assertInertia(fn ($page) => $page->where('people.current_page', 2)->where('people.total', 31)->has('people.data', 6));
    });
});

describe('creating a person', function () {
    it('creates the account with a temporary password shown once', function () {
        $admin = userWithRole(Role::Superadmin);
        $team = Team::factory()->create();

        $response = $this->actingAs($admin)
            ->from(route('admin.people.index'))
            ->post(route('admin.people.store'), personPayload([
                'email' => 'uji@studio.test',
                'employee_code' => 'OWL-001',
                'roles' => [Role::Employee->value, Role::TeamLead->value],
                'team_ids' => [$team->id],
            ]))
            ->assertRedirect(route('admin.people.index'))
            ->assertSessionHasNoErrors();

        $issued = $response->getSession()->get('issued_credentials');
        $user = User::query()->where('username', 'nama.uji')->firstOrFail();

        expect($issued['username'])->toBe('nama.uji')
            ->and($issued['name'])->toBe('Nama Uji')
            ->and($issued['password'])->toMatch('/^[a-z]+-\d-[a-z]+-\d$/')
            ->and(Hash::check($issued['password'], $user->password))->toBeTrue()
            ->and($user->must_change_password)->toBeTrue()
            ->and($user->password_changed_at)->toBeNull()
            ->and($user->status)->toBe(UserStatus::Active)
            ->and($user->hasRole(Role::TeamLead->value))->toBeTrue()
            ->and($user->teams()->pluck('teams.id')->all())->toBe([$team->id]);

        // The next page shows the credentials; the one after that no longer has them.
        $this->get(route('admin.people.index'))->assertInertia(fn ($page) => $page->where('flash.issued_credentials.username', 'nama.uji'));
        $this->get(route('admin.people.index'))->assertInertia(fn ($page) => $page->missing('flash.issued_credentials'));
    });

    it('lets the new person sign in with the temporary password and forces a change', function () {
        $admin = userWithRole(Role::Superadmin);

        $password = $this->actingAs($admin)->post(route('admin.people.store'), personPayload())
            ->getSession()->get('issued_credentials')['password'];

        auth()->logout();

        $this->post(route('sign-in.store'), ['username' => 'nama.uji', 'password' => $password]);
        $this->get(route('my-day'))->assertRedirect(route('password.edit'));
    });

    it('audits the creation without the password', function () {
        $admin = userWithRole(Role::Superadmin);

        $this->actingAs($admin)->post(route('admin.people.store'), personPayload());

        $user = User::query()->where('username', 'nama.uji')->firstOrFail();
        $log = AuditLog::query()->where('action', 'user.created')->sole();

        expect($log->actor_id)->toBe($admin->id)
            ->and($log->subject_id)->toBe($user->id)
            ->and($log->after['username'])->toBe('nama.uji')
            ->and($log->after['roles'])->toBe(['employee'])
            ->and(json_encode($log->after))->not->toContain('password');
    });

    it('validates username format, uniqueness, and at least one role', function () {
        $admin = userWithRole(Role::Superadmin);
        User::factory()->create(['username' => 'sudah.ada', 'email' => 'dipakai@studio.test', 'employee_code' => 'OWL-9']);

        $this->actingAs($admin)->post(route('admin.people.store'), personPayload(['username' => 'Nama Uji']))
            ->assertSessionHasErrors('username');
        $this->actingAs($admin)->post(route('admin.people.store'), personPayload(['username' => 'sudah.ada']))
            ->assertSessionHasErrors('username');
        $this->actingAs($admin)->post(route('admin.people.store'), personPayload(['roles' => []]))
            ->assertSessionHasErrors('roles');
        $this->actingAs($admin)->post(route('admin.people.store'), personPayload(['roles' => ['owner']]))
            ->assertSessionHasErrors('roles.0');
        $this->actingAs($admin)->post(route('admin.people.store'), personPayload(['email' => 'dipakai@studio.test', 'employee_code' => 'OWL-9']))
            ->assertSessionHasErrors(['email', 'employee_code']);
        $this->actingAs($admin)->post(route('admin.people.store'), personPayload(['name' => '', 'team_ids' => [999999]]))
            ->assertSessionHasErrors(['name', 'team_ids.0']);

        $this->actingAs($admin)->post(route('admin.people.store'), personPayload(['username' => 'ok_name-1.x']))
            ->assertSessionHasNoErrors();
    });
});

describe('editing a person', function () {
    it('saves profile, roles, and teams and audits role changes', function () {
        $admin = userWithRole(Role::Superadmin);
        $person = userWithRole(Role::Employee);
        $team = Team::factory()->create();

        $this->actingAs($admin)
            ->put(route('admin.people.update', $person), updatePayload($person, [
                'name' => 'Nama Baru',
                'employee_code' => 'OWL-7',
                'roles' => [Role::Employee->value, Role::ProjectManager->value],
                'team_ids' => [$team->id],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $person->refresh();
        expect($person->name)->toBe('Nama Baru')
            ->and($person->hasRole(Role::ProjectManager->value))->toBeTrue()
            ->and($person->teams()->pluck('teams.id')->all())->toBe([$team->id]);

        $roles = AuditLog::query()->where('action', 'user.roles_changed')->sole();
        expect($roles->before)->toBe(['roles' => ['employee']])
            ->and($roles->after)->toBe(['roles' => ['employee', 'project_manager']]);

        $updated = AuditLog::query()->where('action', 'user.updated')->sole();
        expect($updated->after)->toMatchArray(['name' => 'Nama Baru', 'employee_code' => 'OWL-7', 'team_ids' => [$team->id]]);
    });

    it('does not let the username change', function () {
        $admin = userWithRole(Role::Superadmin);
        $person = userWithRole(Role::Employee);
        $username = $person->username;

        $this->actingAs($admin)->put(route('admin.people.update', $person), updatePayload($person, ['username' => 'baru']));

        expect($person->refresh()->username)->toBe($username);
    });

    it('suspends a person, signs them out everywhere, and audits the status change', function (string $status) {
        $admin = userWithRole(Role::Superadmin);
        $person = userWithRole(Role::Employee);
        $bystander = userWithRole(Role::Employee);

        $person->createToken('device:PC-ANIM-07');
        $bystander->createToken('device:PC-ANIM-08');
        foreach ([$person, $bystander] as $i => $owner) {
            DB::table('sessions')->insert(['id' => "session-$i", 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        }

        $this->actingAs($admin)
            ->put(route('admin.people.update', $person), updatePayload($person, ['status' => $status]))
            ->assertSessionHasNoErrors();

        expect($person->refresh()->status->value)->toBe($status)
            ->and($person->tokens()->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $person->id)->count())->toBe(0)
            ->and($bystander->tokens()->count())->toBe(1)
            ->and(DB::table('sessions')->where('user_id', $bystander->id)->count())->toBe(1);

        $log = AuditLog::query()->where('action', 'user.status_changed')->sole();
        expect($log->before)->toBe(['status' => 'active'])
            ->and($log->after)->toBe(['status' => $status, 'tokens_revoked' => 1, 'sessions_revoked' => 1]);
    })->with(['suspended', 'left']);

    it('keeps people who left in the database with their history', function () {
        $admin = userWithRole(Role::Superadmin);
        $person = userWithRole(Role::Employee);

        $this->actingAs($admin)->put(route('admin.people.update', $person), updatePayload($person, ['status' => 'left']));

        expect(User::withTrashed()->find($person->id)->trashed())->toBeFalse();
    });

    it('clears the Team Lead of teams when the person loses the team_lead role or leaves the team', function () {
        $admin = userWithRole(Role::Superadmin);
        $lead = userWithRole(Role::Employee, Role::TeamLead);
        $kept = Team::factory()->create(['lead_user_id' => $lead->id]);
        $left = Team::factory()->create(['lead_user_id' => $lead->id]);
        $lead->teams()->attach([$kept->id, $left->id]);

        $this->actingAs($admin)->put(route('admin.people.update', $lead), updatePayload($lead, ['team_ids' => [$kept->id]]));
        expect($left->refresh()->lead_user_id)->toBeNull()
            ->and($kept->refresh()->lead_user_id)->toBe($lead->id);

        $this->actingAs($admin)->put(route('admin.people.update', $lead), updatePayload($lead->refresh(), ['roles' => [Role::Employee->value]]));
        expect($kept->refresh()->lead_user_id)->toBeNull();
    });
});

describe('Superadmin guards', function () {
    it('stops a Superadmin from suspending or marking their own account as left', function (string $status) {
        $admin = userWithRole(Role::Superadmin);
        userWithRole(Role::Superadmin);

        $this->actingAs($admin)
            ->put(route('admin.people.update', $admin), updatePayload($admin, ['status' => $status]))
            ->assertSessionHasErrors(['status']);

        expect($admin->refresh()->status)->toBe(UserStatus::Active)
            ->and(AuditLog::query()->count())->toBe(0);
    })->with(['suspended', 'left']);

    it('stops a Superadmin from removing their own Superadmin role', function () {
        $admin = userWithRole(Role::Superadmin);
        userWithRole(Role::Superadmin);

        $this->actingAs($admin)
            ->put(route('admin.people.update', $admin), updatePayload($admin, ['roles' => [Role::Employee->value]]))
            ->assertSessionHasErrors(['roles']);

        expect($admin->refresh()->hasRole(Role::Superadmin->value))->toBeTrue();
    });

    it('protects the last active Superadmin from losing the role or being deactivated', function () {
        $last = userWithRole(Role::Superadmin);
        User::factory()->suspended()->withRole(Role::Superadmin)->create();

        // Someone other than the target, holding the permission directly, so the self guard does not apply.
        $manager = userWithRole(Role::ProjectDirector);
        $manager->givePermissionTo(Permission::ManageUsers->value);

        $this->actingAs($manager)
            ->put(route('admin.people.update', $last), updatePayload($last, ['roles' => [Role::Employee->value]]))
            ->assertSessionHasErrors(['roles']);
        $this->actingAs($manager)
            ->put(route('admin.people.update', $last), updatePayload($last, ['status' => 'suspended']))
            ->assertSessionHasErrors(['status']);

        expect($last->refresh()->hasRole(Role::Superadmin->value))->toBeTrue()
            ->and($last->status)->toBe(UserStatus::Active);
    });

    it('lets a Superadmin demote another Superadmin while one active Superadmin remains', function () {
        $admin = userWithRole(Role::Superadmin);
        $other = userWithRole(Role::Superadmin);

        $this->actingAs($admin)
            ->put(route('admin.people.update', $other), updatePayload($other, ['roles' => [Role::Employee->value], 'status' => 'suspended']))
            ->assertSessionHasNoErrors();

        expect($other->refresh()->hasRole(Role::Superadmin->value))->toBeFalse();

        // Now $admin is the last active Superadmin, and still cannot deactivate themselves.
        $this->actingAs($admin)
            ->put(route('admin.people.update', $admin), updatePayload($admin, ['status' => 'left']))
            ->assertSessionHasErrors(['status']);
    });

    it('allows removing the role from a Superadmin who is already suspended', function () {
        $admin = userWithRole(Role::Superadmin);
        $suspended = User::factory()->suspended()->withRole(Role::Superadmin)->create();

        $this->actingAs($admin)
            ->put(route('admin.people.update', $suspended), updatePayload($suspended, ['roles' => [Role::Employee->value]]))
            ->assertSessionHasNoErrors();
    });
});

describe('resetting a password', function () {
    it('issues a new temporary password once, requires a change, and audits it', function () {
        $admin = userWithRole(Role::Superadmin);
        $person = userWithRole(Role::Employee);
        $oldHash = $person->password;

        $response = $this->actingAs($admin)
            ->from(route('admin.people.index'))
            ->post(route('admin.people.reset-password', $person))
            ->assertRedirect(route('admin.people.index'));

        $issued = $response->getSession()->get('issued_credentials');
        $person->refresh();

        expect($issued['username'])->toBe($person->username)
            ->and(Hash::check($issued['password'], $person->password))->toBeTrue()
            ->and($person->password)->not->toBe($oldHash)
            ->and($person->must_change_password)->toBeTrue();

        $log = AuditLog::query()->where('action', 'user.password_reset')->sole();
        expect($log->subject_id)->toBe($person->id)
            ->and($log->after)->toBe(['must_change_password' => true])
            ->and(json_encode([$log->before, $log->after]))->not->toContain($issued['password']);
    });
});
