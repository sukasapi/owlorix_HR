<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Auth\ImposterSession;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Audit\AuditLog;

beforeEach(function () {
    config(['owlorix.imposter.enabled' => true]);
});

describe('imposter mode gate', function () {
    it('returns 404 when IMPOSTER_MODE is off', function () {
        config(['owlorix.imposter.enabled' => false]);
        $admin = userWithRole(Role::Superadmin);

        $this->actingAs($admin)->get(route('imposter.index'))->assertNotFound();
    });

    it('refuses non-superadmins', function (Role $role) {
        $user = userWithRole($role);

        $this->actingAs($user)->get(route('imposter.index'))->assertForbidden();
    })->with([
        'employee' => [Role::Employee],
        'team lead' => [Role::TeamLead],
        'project manager' => [Role::ProjectManager],
        'project director' => [Role::ProjectDirector],
    ]);
});

describe('imposter start and stop', function () {
    it('starts as the target, shares banner data, and stops back to the actor', function () {
        $admin = userWithRole(Role::Superadmin);
        $target = userWithRole(Role::Employee);

        $this->actingAs($admin)
            ->post(route('imposter.start', $target))
            ->assertRedirect(route('my-day'));

        expect(auth()->id())->toBe($target->id)
            ->and(session(ImposterSession::KEY))->toBe($admin->id);

        $this->get(route('my-day'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.id', $target->id)
                ->where('auth.imposter.active', true)
                ->where('auth.imposter.actor_name', $admin->name)
                ->where('auth.permissions', fn ($perms) => collect($perms)->doesntContain('users.impersonate')));

        $startLog = AuditLog::query()->where('action', 'auth.imposter_started')->sole();
        expect($startLog->actor_id)->toBe($admin->id)
            ->and($startLog->subject_id)->toBe($target->id);

        $this->post(route('imposter.stop'))->assertRedirect(route('imposter.index'));

        expect(auth()->id())->toBe($admin->id)
            ->and(session()->has(ImposterSession::KEY))->toBeFalse();

        $stopLog = AuditLog::query()->where('action', 'auth.imposter_stopped')->sole();
        expect($stopLog->actor_id)->toBe($admin->id);
    });

    it('cannot start on another superadmin or self', function () {
        $admin = userWithRole(Role::Superadmin);
        $otherAdmin = userWithRole(Role::Superadmin);

        $this->actingAs($admin)->post(route('imposter.start', $admin))
            ->assertSessionHasErrors('user');
        $this->actingAs($admin)->post(route('imposter.start', $otherAdmin))
            ->assertSessionHasErrors('user');
    });

    it('blocks nested imposter and password reset while active', function () {
        $admin = userWithRole(Role::Superadmin);
        $target = userWithRole(Role::Employee);
        $other = userWithRole(Role::Employee);

        $this->actingAs($admin)->post(route('imposter.start', $target))->assertRedirect();

        // Target has no ImpersonateUsers; index is forbidden via permission.
        $this->get(route('imposter.index'))->assertForbidden();

        // ManageUsers while impostering: target usually lacks it. Give them ManageUsers via Employee+... 
        // Use a second superadmin path: start as someone who somehow... Plan says block reset while impostering.
        // Start as employee then... employee can't reset. Instead start already as admin, then check session-blocked reset
        // by giving target ManageUsers through assigning Superadmin - but we can't start as superadmin.
        // So create a TeamLead won't have ManageUsers either.
        // The refuseWhileImpostering is on PeopleController; only users with ManageUsers hit it.
        // Give target ManageUsers by also assigning them... Superadmin role is blocked for start.
        // Workaround: after start, manually keep session and login as admin with imposter key still set? That tests stop.
        // Better: temporarily assign ManageUsers permission to employee for this test.
        $target->givePermissionTo('users.manage');

        $this->actingAs($target)
            ->withSession([ImposterSession::KEY => $admin->id])
            ->post(route('admin.people.reset-password', $other))
            ->assertSessionHasErrors('imposter');
    });
});
