<?php

use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\WebDevice;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\Device;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Support\Str;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\WebClock\Support\Browser;

// Perangkat (01 section 6, 03 section 5). Monday 2026-09-14, Asia/Jakarta.

beforeEach(function () {
    $this->travelTo(Desk::time('2026-09-14 08:00'));
    $this->admin = userWithRole(Role::Superadmin);
    $this->person = userWithRole(Role::Employee);
});

function browserDevice(array $overrides = []): Device
{
    return Device::factory()->create([
        'id' => 'web:'.Str::random(32),
        'hostname' => 'Browser: Chrome, Windows',
        'app_version' => 'web',
        ...$overrides,
    ]);
}

describe('authorization', function () {
    it('shows the page to Superadmin and puts it in the menu', function () {
        $this->actingAs($this->admin)->get(route('admin.devices.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/devices/Index')
                ->where('nav', fn ($nav) => collect(collect($nav)->firstWhere('group', 'admin')['items'])->contains('key', 'devices')));
    });

    it('refuses everyone else on every device route', function (Role $role) {
        $user = userWithRole($role);
        $device = Device::factory()->create();

        $this->actingAs($user)->get(route('admin.devices.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.devices.revoke', $device), ['reason' => 'PC dijual studio'])->assertForbidden();
        $this->actingAs($user)->post(route('admin.devices.restore', $device), ['reason' => 'PC dipakai lagi'])->assertForbidden();

        expect($device->fresh()->revoked_at)->toBeNull();
    })->with([
        'employee' => [Role::Employee],
        'team lead' => [Role::TeamLead],
        'project manager' => [Role::ProjectManager],
        'project director' => [Role::ProjectDirector],
    ]);

    it('sends guests to sign in', function () {
        $this->get(route('admin.devices.index'))->assertRedirect(route('sign-in'));
    });
});

describe('list', function () {
    it('lists desktop PCs and browsers apart, with people, last sign-in, and the shift running on each', function () {
        $desk = Desk::for($this, $this->person, 'PC-ANIM-07');
        $desk->send('clock_in', at: '09:02');
        $idle = Device::factory()->create(['hostname' => 'PC-RENDER-01', 'last_seen_at' => Desk::time('2026-09-10 17:00')]);
        $browser = browserDevice();
        $browser->users()->attach($this->person->id, ['last_online_sign_in_at' => '2026-09-13 02:00:00.000']);

        $this->actingAs($this->admin)->get(route('admin.devices.index'))
            ->assertInertia(fn ($page) => $page
                ->where('filters.kind', 'desktop')
                ->where('counts', ['desktop' => 2, 'browser' => 1])
                ->has('devices.data', 2)
                ->where('devices.data.0.id', $desk->device->id)
                ->where('devices.data.0.kind', 'desktop')
                ->where('devices.data.0.people.0.username', $this->person->username)
                ->where('devices.data.0.open_shifts.0.name', $this->person->name)
                ->where('devices.data.0.open_shifts.0.clock_in_at', '2026-09-14T02:02:00.000Z')
                ->where('devices.data.1.id', $idle->id)
                ->where('devices.data.1.open_shifts', [])
                ->where('devices.data.1.people', []));

        $this->actingAs($this->admin)->get(route('admin.devices.index', ['jenis' => 'browser']))
            ->assertInertia(fn ($page) => $page
                ->has('devices.data', 1)
                ->where('devices.data.0.id', $browser->id)
                ->where('devices.data.0.kind', 'browser')
                ->where('devices.data.0.people.0.last_online_sign_in_at', '2026-09-13T02:00:00.000Z'));
    });

    it('searches by hostname or by a person who signed in, and filters revoked devices', function () {
        $desk = Desk::for($this, $this->person, 'PC-ANIM-07');
        Device::factory()->revoked()->create(['hostname' => 'PC-LAMA-03']);

        $this->actingAs($this->admin)->get(route('admin.devices.index', ['q' => $this->person->username]))
            ->assertInertia(fn ($page) => $page->has('devices.data', 1)->where('devices.data.0.id', $desk->device->id));

        $this->actingAs($this->admin)->get(route('admin.devices.index', ['q' => 'lama']))
            ->assertInertia(fn ($page) => $page->has('devices.data', 1)->where('devices.data.0.hostname', 'PC-LAMA-03'));

        $this->actingAs($this->admin)->get(route('admin.devices.index', ['status' => 'revoked']))
            ->assertInertia(fn ($page) => $page->has('devices.data', 1)->where('devices.data.0.hostname', 'PC-LAMA-03'));

        $this->actingAs($this->admin)->get(route('admin.devices.index', ['status' => 'active']))
            ->assertInertia(fn ($page) => $page->has('devices.data', 1)->where('devices.data.0.hostname', 'PC-ANIM-07'));
    });
});

describe('revoke and restore', function () {
    it('needs a reason', function () {
        $device = Device::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.devices.revoke', $device), ['reason' => ''])
            ->assertSessionHasErrors(['reason' => 'reason_required']);
        $this->actingAs($this->admin)->post(route('admin.devices.revoke', $device), ['reason' => 'x'])
            ->assertSessionHasErrors(['reason' => 'reason_short']);

        expect($device->fresh()->revoked_at)->toBeNull()
            ->and(AuditLog::query()->count())->toBe(0);
    });

    it('asks to confirm when a shift is running on the device, then revokes it, deletes its tokens, and audits', function () {
        $desk = Desk::for($this, $this->person, 'PC-ANIM-07');
        $desk->send('clock_in', at: '09:00');
        $other = Desk::for($this, $this->person, 'PC-LIGHT-02');

        $this->actingAs($this->admin)->post(route('admin.devices.revoke', $desk->device), ['reason' => 'PC dipindah ke gudang'])
            ->assertSessionHasErrors(['confirm_open_shift' => 'open_shift']);

        expect($desk->device->fresh()->revoked_at)->toBeNull();

        $this->travelTo(Desk::time('09:30'));
        $this->actingAs($this->admin)->post(route('admin.devices.revoke', $desk->device), ['reason' => 'PC dipindah ke gudang', 'confirm_open_shift' => true])
            ->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('action', 'device.revoked')->sole();

        expect($desk->device->fresh()->revoked_at->toIso8601ZuluString())->toBe('2026-09-14T02:30:00Z')
            ->and($this->person->tokens()->pluck('name')->all())->toBe([$other->device->id])
            ->and($log->actor_id)->toBe($this->admin->id)
            ->and($log->before)->toMatchArray(['device_id' => $desk->device->id, 'hostname' => 'PC-ANIM-07', 'status' => 'active'])
            ->and($log->after)->toMatchArray([
                'device_id' => $desk->device->id,
                'kind' => 'desktop',
                'status' => 'revoked',
                'reason' => 'PC dipindah ke gudang',
                'tokens_deleted' => 1,
                'open_shifts' => [$this->person->name],
            ]);

        // The desktop app on that PC is refused: its token is gone, and signing in there again is refused
        $desk->sync([$desk->event('heartbeat')])->assertUnauthorized();
        $this->postJson('/api/v1/auth/device-login', [
            'username' => $this->person->username,
            'password' => 'password-for-tests',
            'device_id' => $desk->device->id,
            'hostname' => 'PC-ANIM-07',
            'app_version' => '0.1.0',
        ])->assertForbidden()->assertJsonPath('code', 'device_revoked');

        // The other PC of the same person keeps working
        $other->state();
    });

    it('refuses a token that still names a revoked device on sync', function () {
        $desk = Desk::for($this, $this->person, 'PC-ANIM-07');
        $desk->device->forceFill(['revoked_at' => now()])->save();

        $desk->sync([$desk->event('clock_in')])->assertUnauthorized()->assertJsonPath('code', 'device_revoked');

        expect(Shift::query()->count())->toBe(0);
    });

    it('restores a revoked device with a reason, audited, and the PC can sign in again', function () {
        $device = Device::factory()->revoked()->create(['hostname' => 'PC-ANIM-07']);

        $this->actingAs($this->admin)->post(route('admin.devices.restore', $device), [])
            ->assertSessionHasErrors(['reason' => 'reason_required']);

        $this->actingAs($this->admin)->post(route('admin.devices.restore', $device), ['reason' => 'Salah cabut, PC masih dipakai'])
            ->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('action', 'device.restored')->sole();

        expect($device->fresh()->revoked_at)->toBeNull()
            ->and($log->before['status'])->toBe('revoked')
            ->and($log->after)->toMatchArray(['status' => 'active', 'reason' => 'Salah cabut, PC masih dipakai', 'device_id' => $device->id]);

        $this->postJson('/api/v1/auth/device-login', [
            'username' => $this->person->username,
            'password' => 'password-for-tests',
            'device_id' => $device->id,
            'hostname' => 'PC-ANIM-07',
            'app_version' => '0.1.0',
        ])->assertOk();
    });

    it('does nothing when the device is already in the asked state', function () {
        $device = Device::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.devices.restore', $device), ['reason' => 'Tidak perlu apa-apa'])->assertSessionHasNoErrors();

        expect(AuditLog::query()->count())->toBe(0);
    });
});

describe('revoked browser', function () {
    it('cannot clock in or out until the person signs in again, then gets a new device id', function () {
        // Signed in on the web at 08:00, clocked in from this browser at 09:00
        $this->post('/masuk', ['username' => $this->person->username, 'password' => 'password-for-tests'])->assertRedirect();
        $browser = new Browser($this, $this->person);
        $browser->post('/absen/masuk', at: '2026-09-14 09:00')->assertSessionHasNoErrors();
        $revokedId = $browser->deviceId;

        $this->travelTo(Desk::time('10:00'));
        app('auth')->forgetGuards();
        $this->actingAs($this->admin)->post(route('admin.devices.revoke', $revokedId), ['reason' => 'Laptop pinjaman hilang', 'confirm_open_shift' => true])
            ->assertSessionHasNoErrors();

        // Still the sign-in from before the revoke: every clock action is refused, heartbeats are ignored
        $browser->post('/absen/pulang', at: '10:05')->assertSessionHasErrors(['clock' => WebDevice::REVOKED_MESSAGE]);
        $browser->post('/absen/detak', at: '10:06')->assertSessionHasNoErrors();
        $browser->post('/absen/masuk', at: '10:07')->assertSessionHasErrors(['clock' => WebDevice::REVOKED_MESSAGE]);

        expect($browser->deviceId)->toBe($revokedId)
            ->and($browser->shift()->clock_out_at)->toBeNull()
            ->and($browser->shift()->last_seen_at->toIso8601ZuluString())->toBe('2026-09-14T02:00:00Z');

        // Signing out and in again gives the browser a new id; the shift moves over from the revoked one
        $this->travelTo(Desk::time('10:10'));
        app('auth')->forgetGuards();
        $this->actingAs($this->person)->post('/keluar')->assertRedirect(route('sign-in'));
        app('auth')->forgetGuards();
        $this->post('/masuk', ['username' => $this->person->username, 'password' => 'password-for-tests'])->assertRedirect();

        $browser->post('/absen/pindah', at: '10:11')->assertSessionHasNoErrors();

        expect($browser->deviceId)->toStartWith('web:')->not->toBe($revokedId)
            ->and(Device::query()->find($browser->deviceId)->revoked_at)->toBeNull()
            ->and(Device::query()->find($revokedId)->revoked_at)->not->toBeNull()
            ->and($browser->summary()['open_shift']['on_this_browser'])->toBeTrue();
    });

    it('refuses a revoked browser for a session that never signed in through the form', function () {
        $browser = new Browser($this, $this->person);
        $browser->post('/absen/masuk', at: '2026-09-14 09:00');
        $browser->post('/absen/pulang', at: '09:30');
        Device::query()->whereKey($browser->deviceId)->update(['revoked_at' => Desk::time('09:40')->format('Y-m-d H:i:s.v')]);

        $browser->post('/absen/masuk', at: '10:00')->assertSessionHasErrors(['clock' => WebDevice::REVOKED_MESSAGE]);

        expect(Shift::query()->count())->toBe(1);
    });
});
