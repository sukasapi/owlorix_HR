<?php

use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\Device;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Shared\Settings\Settings;
use Illuminate\Support\Str;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\WebClock\Support\Browser;

// Web clock endpoints (docs/02-attendance-rules.md 3.11, docs/03-architecture.md 4.4): who can act on which shift.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->browser = new Browser($this, $this->person);
});

test('"Lanjutkan shift" from another browser does not take the shift without the move question', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('09:20');
    $phone = new Browser($this, $this->person, Browser::SAFARI_IPHONE);

    $phone->post('/absen/lanjut', at: '09:40')->assertSessionHasErrors(['clock' => 'moved_elsewhere']);

    expect($this->browser->summary()['open_shift'])
        ->toMatchArray(['device_id' => $this->browser->deviceId, 'status' => 'interrupted'])
        ->and(AttendanceEvent::query()->pluck('type')->map->value->all())->toBe(['clock_in']);
});

test('a person cannot write a report or a late claim for someone else\'s shift', function () {
    app(Settings::class)->set('attendance.regular_limit_minutes', 60);
    $other = new Browser($this, userWithRole(Role::Employee));
    $other->post('/absen/masuk', at: '2026-09-14 09:00');
    $other->keepAlive('10:04');
    $other->post('/absen/lembur', ['reason' => 'Render final shot 12'], '10:05');
    $other->keepAlive('10:40');
    $other->post('/absen/pulang', at: '10:45');
    $shift = $other->shift();

    $this->browser->post('/absen/laporan', ['shift_id' => $shift->id, 'work_report' => 'Bukan laporanku'])
        ->assertSessionHasErrors(['work_report' => 'report_not_due']);
    $this->browser->post('/absen/klaim', ['shift_id' => $shift->id, 'reason' => 'Masih render di rumah', 'work_report' => 'Render', 'ended_at' => '2026-09-14 10:40'])
        ->assertSessionHasErrors(['ended_at' => 'shift_not_found']);

    expect($shift->refresh()->status)->toBe(ShiftStatus::ReportDue)
        ->and(OvertimeRequest::query()->sole()->work_report)->toBeNull()
        ->and(AttendanceEvent::query()->where('user_id', $this->person->id)->count())->toBe(0);
});

test('a device cookie naming a desktop PC is ignored and the browser gets its own id', function () {
    $desk = Desk::for($this, $this->person, 'PC-ANIM-07');
    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->heartbeat('09:58');
    $this->browser->deviceId = $desk->device->id;

    $this->browser->post('/absen/pulang', at: '10:00')->assertSessionHasErrors(['clock' => 'moved_elsewhere']);
    $this->browser->post('/absen/detak', at: '10:01');

    expect($this->browser->deviceId)->toStartWith('web:')->not->toBe($desk->device->id)
        ->and($desk->shift()->last_seen_at->toIso8601ZuluString())->toBe('2026-09-14T02:58:00Z')
        ->and($desk->device->refresh()->hostname)->toBe('PC-ANIM-07')
        ->and($desk->state()['shift']['status'])->toBe('open');
});

test('a device cookie copied from another person\'s browser gives no access to their shift', function () {
    $owner = new Browser($this, userWithRole(Role::Employee));
    $owner->post('/absen/masuk', at: '2026-09-14 09:00');
    $owner->keepAlive('09:10');
    $this->browser->deviceId = $owner->deviceId;

    $this->browser->post('/absen/pulang', at: '09:12')->assertSessionHasErrors(['clock' => 'no_open_shift']);
    $this->browser->post('/absen/detak', at: '09:13');
    $this->browser->post('/absen/batal')->assertSessionHasErrors(['clock' => 'no_open_shift']);

    expect($owner->shift()->last_seen_at->toIso8601ZuluString())->toBe('2026-09-14T02:10:00Z')
        ->and($owner->summary()['status'])->toBe('open')
        ->and(AttendanceEvent::query()->where('user_id', $this->person->id)->count())->toBe(0);
});

test('a web id that belongs to a desktop device is not reused for a browser', function () {
    $desktop = Device::factory()->create(['id' => 'web:'.Str::random(32), 'hostname' => 'PC-ANIM-09', 'app_version' => '0.1.0']);
    $this->browser->deviceId = $desktop->id;

    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00')->assertSessionHasNoErrors();

    expect($this->browser->deviceId)->not->toBe($desktop->id)
        ->and($desktop->refresh()->hostname)->toBe('PC-ANIM-09')
        ->and(AttendanceEvent::query()->sole()->device_id)->toBe($this->browser->deviceId);
});

test('a desktop app cannot sign in with a device id reserved for browsers', function () {
    $this->travelTo(Desk::time('2026-09-14 08:00'));

    $this->postJson('/api/v1/auth/device-login', [
        'username' => $this->person->username,
        'password' => 'password-for-tests',
        'device_id' => 'web:'.Str::random(32),
        'hostname' => 'PC-ANIM-07',
        'app_version' => '0.1.0',
    ])->assertUnprocessable()->assertJsonValidationErrors('device_id');

    expect(Device::query()->count())->toBe(0);
});

test('extra heartbeats within 30 seconds, for example from a second tab, are not written', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');

    $this->browser->post('/absen/detak', at: '09:01:00');
    $this->browser->post('/absen/detak', at: '09:01:20');

    expect($this->browser->shift()->last_seen_at->toIso8601ZuluString())->toBe('2026-09-14T02:01:00Z');

    $this->browser->post('/absen/detak', at: '09:01:31');

    expect($this->browser->shift()->last_seen_at->toIso8601ZuluString())->toBe('2026-09-14T02:01:31Z');
});

test('web clock requests are limited to 60 a minute per person', function () {
    $this->browser->at('2026-09-14 09:00');

    foreach (range(1, 60) as $i) {
        $this->browser->post('/absen/detak')->assertRedirect('/');
    }

    $this->browser->post('/absen/detak')->assertTooManyRequests();
    (new Browser($this, userWithRole(Role::Employee)))->post('/absen/detak')->assertRedirect('/');
});
