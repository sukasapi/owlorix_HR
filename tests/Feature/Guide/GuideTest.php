<?php

use App\Modules\Identity\Access\Role;

// docs/13 section 7: Panduan for everyone who is signed in.

test('every signed-in person opens the guide', function (Role $role) {
    $this->actingAs(userWithRole($role))->get(route('guide.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('guide/Index'));
})->with([Role::Employee, Role::TeamLead, Role::Superadmin]);

test('a guest is sent to sign in', function () {
    $this->get(route('guide.index'))->assertRedirect(route('sign-in'));
});

test('the guide is in the menu of every person', function () {
    $this->actingAs(userWithRole(Role::Superadmin))->get(route('guide.index'))
        ->assertInertia(fn ($page) => $page->where('nav', fn ($groups) => collect($groups)->last()['group'] === 'more'
            && collect(collect($groups)->last()['items'])->last()['key'] === 'guide'));
});
