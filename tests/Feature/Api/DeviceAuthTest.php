<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Attendance\Support\Desk;

function deviceLogin(array $overrides = []): array
{
    return array_merge([
        'password' => 'password-for-tests',
        'device_id' => 'PC-ANIM-07:3f9c',
        'hostname' => 'PC-ANIM-07',
        'app_version' => '0.1.0',
    ], $overrides);
}

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->travelTo(Desk::time('2026-09-14 08:00'));
});

it('signs in a device and returns a token named with the device id, the profile and permissions', function () {
    $this->person->forceFill(['must_change_password' => true])->save();

    $response = $this->postJson('/api/v1/auth/device-login', deviceLogin(['username' => $this->person->username]))
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.username', $this->person->username)
        ->assertJsonPath('user.must_change_password', true)
        ->assertJsonPath('user.permissions', [Permission::ClockIn->value])
        ->assertJsonPath('device.id', 'PC-ANIM-07:3f9c');

    $device = Device::query()->findOrFail('PC-ANIM-07:3f9c');

    expect($response->json('token'))->toBeString()
        ->and($this->person->tokens()->sole()->name)->toBe('PC-ANIM-07:3f9c')
        ->and($device->hostname)->toBe('PC-ANIM-07')
        ->and($device->last_seen_at->toIso8601ZuluString())->toBe('2026-09-14T01:00:00Z')
        ->and($device->users()->sole()->pivot->last_online_sign_in_at)->toStartWith('2026-09-14 01:00:00');
});

it('replaces the earlier token of the same person on the same PC', function () {
    $this->postJson('/api/v1/auth/device-login', deviceLogin(['username' => $this->person->username]))->assertOk();
    $this->postJson('/api/v1/auth/device-login', deviceLogin(['username' => $this->person->username, 'app_version' => '0.1.1']))->assertOk();

    expect($this->person->tokens()->count())->toBe(1)
        ->and(Device::query()->sole()->app_version)->toBe('0.1.1');
});

it('signs in a device with the email address instead of the username', function () {
    $this->postJson('/api/v1/auth/device-login', deviceLogin(['username' => $this->person->email]))
        ->assertOk()
        ->assertJsonPath('user.username', $this->person->username);
});

it('refuses a wrong password as JSON without saying which part was wrong', function () {
    $this->post('/api/v1/auth/device-login', deviceLogin(['username' => $this->person->username, 'password' => 'wrong-password']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['username' => __('auth.failed')]);
});

it('makes a username wait after 5 failures while colleagues on the same IP still sign in', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/device-login', deviceLogin(['username' => $this->person->username, 'password' => 'wrong-password']));
    }

    $this->postJson('/api/v1/auth/device-login', deviceLogin(['username' => $this->person->username]))
        ->assertStatus(429)
        ->assertJsonPath('code', 'throttled')
        ->assertHeader('Retry-After');

    $colleague = userWithRole(Role::Employee);
    $this->postJson('/api/v1/auth/device-login', deviceLogin(['username' => $colleague->username]))->assertOk();
});

it('refuses inactive people', function () {
    $suspended = User::factory()->suspended()->withRole(Role::Employee)->create();

    $this->postJson('/api/v1/auth/device-login', deviceLogin(['username' => $suspended->username]))
        ->assertForbidden()
        ->assertJsonPath('code', 'inactive');
});

it('refuses sign-in on a revoked device and cuts off its existing tokens', function () {
    $desk = Desk::for($this, $this->person);
    $desk->device->forceFill(['revoked_at' => now()])->save();

    $this->postJson('/api/v1/auth/device-login', deviceLogin(['username' => $this->person->username, 'device_id' => $desk->device->id]))
        ->assertForbidden()
        ->assertJsonPath('code', 'device_revoked');

    $desk->request('GET', '/api/v1/me/shift')->assertUnauthorized()->assertJsonPath('code', 'device_revoked');

    expect($this->person->tokens()->count())->toBe(0);
});

it('answers requests without a token with JSON 401, whatever the Accept header', function () {
    $this->post('/api/v1/sync/events')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    $this->get('/api/v1/me/shift')->assertUnauthorized();
});

it('refuses a suspended person with a valid token', function () {
    $desk = Desk::for($this, $this->person);
    $this->person->forceFill(['status' => 'suspended'])->save();

    $desk->request('GET', '/api/v1/config')->assertForbidden()->assertJsonPath('code', 'inactive');
});

it('logs out by revoking only this token and leaves the shift open', function () {
    $desk = Desk::for($this, $this->person);
    $other = Desk::for($this, $this->person, 'PC-LIGHT-02');
    $desk->send('clock_in', at: '09:00');

    $desk->request('POST', '/api/v1/auth/logout')->assertNoContent();

    $desk->request('GET', '/api/v1/me/shift')->assertUnauthorized();
    $other->request('GET', '/api/v1/me/shift')->assertOk()->assertJsonPath('shift.status', 'open');
});

it('changes the password from the desktop app and clears the forced change', function () {
    $this->person->forceFill(['must_change_password' => true])->save();
    $desk = Desk::for($this, $this->person);

    $desk->request('POST', '/api/v1/auth/password', [
        'current_password' => 'wrong-password',
        'password' => 'awan-9-sungai-3-baru',
        'password_confirmation' => 'awan-9-sungai-3-baru',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

    $desk->request('POST', '/api/v1/auth/password', [
        'current_password' => 'password-for-tests',
        'password' => 'pendek-1',
        'password_confirmation' => 'pendek-1',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');

    $desk->request('POST', '/api/v1/auth/password', [
        'current_password' => 'password-for-tests',
        'password' => 'awan-9-sungai-3-baru',
        'password_confirmation' => 'awan-9-sungai-3-baru',
    ])->assertOk()->assertJsonPath('must_change_password', false);

    $this->person->refresh();

    expect($this->person->must_change_password)->toBeFalse()
        ->and(Hash::check('awan-9-sungai-3-baru', $this->person->password))->toBeTrue();
});
