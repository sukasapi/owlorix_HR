<?php

use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Attendance\Support\Desk;

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->desk = Desk::for($this, $this->person);
    $this->desk->at('2026-09-14 09:00');
});

it('takes the person from the token, never from the event', function () {
    $someoneElse = userWithRole(Role::Employee);

    $this->desk->sync([$this->desk->event('clock_in', overrides: ['user_id' => $someoneElse->id, 'device_id' => 'PC-OTHER:0000'])])->assertOk();

    expect(AttendanceEvent::query()->sole())
        ->user_id->toBe($this->person->id)
        ->device_id->toBe($this->desk->device->id)
        ->and(Shift::query()->where('user_id', $someoneElse->id)->exists())->toBeFalse();
});

it('accepts at most 200 events per batch', function () {
    $events = array_map(fn () => $this->desk->event('idle_tag', ['tag' => 'break']), range(1, 201));

    $this->desk->sync($events)->assertUnprocessable()->assertJsonValidationErrors('events');
    $this->desk->sync(array_slice($events, 0, 200))->assertOk();
});

it('refuses unknown event types and the server-only claim event', function (string $type) {
    $this->desk->sync([$this->desk->event($type)])->assertOk()
        ->assertJsonPath('rejected.0.code', 'invalid')
        ->assertJsonPath('accepted', []);

    expect(AttendanceEvent::query()->count())->toBe(0);
})->with(['coffee_break', 'overtime_claim']);

it('lets only people who may clock in sync events', function () {
    $noRole = User::factory()->create();
    $desk = Desk::for($this, $noRole, 'PC-GUEST-01');

    $desk->sync([$desk->event('clock_in')])->assertForbidden();
    $desk->request('GET', '/api/v1/me/shift')->assertOk();

    expect(AttendanceEvent::query()->count())->toBe(0);
});

it('returns the open shift, today\'s regular minutes and server time', function () {
    $this->desk->send('clock_in', at: '08:00');

    $this->desk->at('10:30');
    $state = $this->desk->heartbeat()->json();

    expect($state)
        ->server_time->toBe('2026-09-14T03:30:00.000Z')
        ->work_date->toBe('2026-09-14')
        ->is_workday->toBeTrue()
        ->regular_limit_minutes->toBe(480)
        ->regular_minutes->toBe(150)
        ->and($state['shift']['status'])->toBe('open')
        ->and($state['shift']['regular_ends_at'])->toBe('2026-09-14T09:00:00.000Z')
        ->and($this->desk->state())->toEqual(array_diff_key($state, array_flip(['accepted', 'duplicates', 'rejected'])));
});

it('returns no shift when the person is not clocked in', function () {
    $this->desk->request('GET', '/api/v1/me/shift')->assertOk()
        ->assertJsonPath('shift', null)
        ->assertJsonPath('regular_minutes', 0)
        ->assertJsonPath('reports_due', []);
});

it('gives the app every setting, its calendar and empty version info', function () {
    $config = $this->desk->request('GET', '/api/v1/config')->assertOk()->json();

    expect($config['settings'])->toHaveKeys(['attendance.regular_limit_minutes', 'attendance.idle_threshold_minutes', 'sync.heartbeat_upload_seconds', 'overtime.late_claim_hours'])
        ->and($config['timezone'])->toBe('Asia/Jakarta')
        ->and($config['calendar'][0])->toBe(['date' => '2026-09-14', 'is_workday' => true, 'source' => 'work_week', 'calendar_type' => null, 'label' => null])
        ->and($config['app'])->toBe(['latest_version' => null, 'min_version' => null, 'download_url' => null]);
});

it('limits requests per device token, not per IP', function () {
    foreach (range(1, 120) as $hit) {
        RateLimiter::hit('desktop-api:'.$this->desk->tokenId(), 60);
    }

    $this->desk->request('GET', '/api/v1/me/shift')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    Desk::for($this, userWithRole(Role::Employee), 'PC-ANIM-08')->request('GET', '/api/v1/me/shift')->assertOk();
});

it('refuses a late claim for a shift closed by the person', function () {
    $this->desk->send('clock_in', at: '09:00');
    $this->desk->send('clock_out', at: '17:10');

    $this->desk->at('18:00');
    $this->desk->request('POST', "/api/v1/overtime/{$this->desk->shift()->id}/claim", [
        'reason' => 'Masih render, prompt tidak terlihat',
        'work_report' => 'Render shot 7',
        'ended_at' => '2026-09-14T10:50:00Z',
    ])->assertUnprocessable()->assertJsonPath('code', 'not_claimable');
});

it('refuses a late claim for someone else\'s shift', function () {
    $colleague = Desk::for($this, userWithRole(Role::Employee), 'PC-ANIM-08');
    $colleague->send('clock_in', at: '09:00');

    $this->desk->request('POST', "/api/v1/overtime/{$colleague->shift()->id}/claim", [
        'reason' => 'Masih render, prompt tidak terlihat',
        'work_report' => 'Render shot 7',
        'ended_at' => '2026-09-14T10:50:00Z',
    ])->assertNotFound();
});

it('refuses a second late claim and an end time before the automatic close', function () {
    $this->desk->send('clock_in', at: '09:00');
    $this->desk->heartbeat('17:40');
    $this->desk->heartbeat('18:45');
    $id = $this->desk->shift()->id;
    $claim = ['reason' => 'Masih render, prompt tidak terlihat', 'work_report' => 'Render shot 7'];

    $this->desk->at('19:00');
    $this->desk->request('POST', "/api/v1/overtime/{$id}/claim", [...$claim, 'ended_at' => '2026-09-14T09:59:00Z'])
        ->assertUnprocessable()->assertJsonValidationErrors('ended_at');

    $this->desk->request('POST', "/api/v1/overtime/{$id}/claim", [...$claim, 'ended_at' => '2026-09-14T11:00:00Z'])->assertCreated();
    $this->desk->request('POST', "/api/v1/overtime/{$id}/claim", [...$claim, 'ended_at' => '2026-09-14T11:30:00Z'])
        ->assertUnprocessable()->assertJsonPath('code', 'already_claimed');
});
