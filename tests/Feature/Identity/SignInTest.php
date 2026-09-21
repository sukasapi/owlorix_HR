<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Settings\Settings;

it('tells the sign-in page whether web clock-in is on (3.11.8)', function () {
    $this->get(route('sign-in'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/SignIn')->where('web_clock_in_enabled', true));

    app(Settings::class)->set('attendance.web_clock_in', false);

    $this->get(route('sign-in'))
        ->assertInertia(fn ($page) => $page->where('web_clock_in_enabled', false));
});

it('signs in with username and password', function () {
    $user = userWithRole(Role::Employee);

    $this->post(route('sign-in.store'), ['username' => $user->username, 'password' => 'password-for-tests'])
        ->assertRedirect(route('my-day'));

    $this->assertAuthenticatedAs($user);
});

it('signs in with the email address instead of the username', function () {
    $user = userWithRole(Role::Employee);

    $this->post(route('sign-in.store'), ['username' => $user->email, 'password' => 'password-for-tests'])
        ->assertRedirect(route('my-day'));

    $this->assertAuthenticatedAs($user);
});

it('does not sign anyone in for an email nobody owns', function () {
    userWithRole(Role::Employee);

    $this->from(route('sign-in'))
        ->post(route('sign-in.store'), ['username' => 'bukan.siapa-siapa@owlorix.com', 'password' => 'password-for-tests'])
        ->assertSessionHasErrors(['username' => __('auth.failed')]);

    $this->assertGuest();
});

it('rejects a wrong password without saying which part was wrong', function () {
    $user = userWithRole(Role::Employee);

    $this->from(route('sign-in'))
        ->post(route('sign-in.store'), ['username' => $user->username, 'password' => 'wrong-password'])
        ->assertRedirect(route('sign-in'))
        ->assertSessionHasErrors(['username' => __('auth.failed')]);

    $this->assertGuest();
});

it('blocks suspended accounts even with the right password', function () {
    $user = User::factory()->suspended()->withRole(Role::Employee)->create();

    $this->post(route('sign-in.store'), ['username' => $user->username, 'password' => 'password-for-tests'])
        ->assertSessionHasErrors(['username' => __('auth.inactive')]);

    $this->assertGuest();
});

it('makes a username wait after 5 failures, and counts per username not per IP', function () {
    $locked = userWithRole(Role::Employee);
    $colleague = userWithRole(Role::Employee);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('sign-in.store'), ['username' => $locked->username, 'password' => 'wrong-password']);
    }

    $this->post(route('sign-in.store'), ['username' => $locked->username, 'password' => 'password-for-tests'])
        ->assertSessionHasErrors('username');
    $this->assertGuest();

    // Same studio IP, different username: still allowed.
    $this->post(route('sign-in.store'), ['username' => $colleague->username, 'password' => 'password-for-tests'])
        ->assertRedirect(route('my-day'));
    $this->assertAuthenticatedAs($colleague);
});

it('counts failures on the email and the username of one person together', function () {
    $user = userWithRole(Role::Employee);

    foreach (range(1, 3) as $attempt) {
        $this->post(route('sign-in.store'), ['username' => $user->username, 'password' => 'wrong-password']);
    }

    foreach (range(1, 2) as $attempt) {
        $this->post(route('sign-in.store'), ['username' => $user->email, 'password' => 'wrong-password']);
    }

    $this->post(route('sign-in.store'), ['username' => $user->username, 'password' => 'password-for-tests'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('lets the username try again after the wait', function () {
    $user = userWithRole(Role::Employee);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('sign-in.store'), ['username' => $user->username, 'password' => 'wrong-password']);
    }

    $this->travel(61)->seconds();

    $this->post(route('sign-in.store'), ['username' => $user->username, 'password' => 'password-for-tests'])
        ->assertRedirect(route('my-day'));
});

it('sends people with an issued password to the change password page first', function () {
    $user = User::factory()->mustChangePassword()->withRole(Role::Employee)->create();

    $this->actingAs($user)->get(route('my-day'))->assertRedirect(route('password.edit'));
});

it('changes the issued password and unlocks the app', function () {
    $user = User::factory()->mustChangePassword()->withRole(Role::Employee)->create();

    $this->actingAs($user)
        ->put(route('password.update'), [
            'current_password' => 'password-for-tests',
            'password' => 'awan-9-sungai-3-baru',
            'password_confirmation' => 'awan-9-sungai-3-baru',
        ])
        ->assertRedirect(route('my-day'));

    $user->refresh();
    expect($user->must_change_password)->toBeFalse()
        ->and($user->password_changed_at)->not->toBeNull();

    $this->actingAs($user)->get(route('my-day'))->assertOk();
});

it('refuses a new password shorter than 12 characters or equal to the old one', function () {
    $user = User::factory()->mustChangePassword()->withRole(Role::Employee)->create();

    $this->actingAs($user)
        ->put(route('password.update'), [
            'current_password' => 'password-for-tests',
            'password' => 'short-1',
            'password_confirmation' => 'short-1',
        ])
        ->assertSessionHasErrors('password');

    $this->actingAs($user)
        ->put(route('password.update'), [
            'current_password' => 'password-for-tests',
            'password' => 'password-for-tests',
            'password_confirmation' => 'password-for-tests',
        ])
        ->assertSessionHasErrors('password');
});

it('signs out someone who was suspended while signed in', function () {
    $user = userWithRole(Role::Employee);

    $this->actingAs($user)->get(route('my-day'))->assertOk();

    $user->update(['status' => 'suspended']);

    $this->actingAs($user)->get(route('my-day'))
        ->assertRedirect(route('sign-in'))
        ->assertSessionHasErrors(['username' => __('auth.inactive')]);
    $this->assertGuest();
});

it('signs out', function () {
    $user = userWithRole(Role::Employee);

    $this->actingAs($user)->post(route('sign-out'))->assertRedirect(route('sign-in'));
    $this->assertGuest();
});

it('saves language and theme preferences', function () {
    $user = userWithRole(Role::Employee);

    $this->actingAs($user)->patch(route('preferences.update'), ['locale' => 'en', 'theme' => 'dark'])->assertRedirect();

    expect($user->refresh())->locale->toBe('en')->theme->toBe('dark');
});
