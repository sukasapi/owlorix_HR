<?php

use App\Modules\Attendance\Enums\EndReason;
use App\Modules\Attendance\Enums\OvertimeEndReason;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\WebDevice;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Shared\Settings\Settings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\WebClock\Support\Browser;

// docs/02-attendance-rules.md 3.11. Monday 2026-09-14, Saturday 2026-09-19, Asia/Jakarta.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->browser = new Browser($this, $this->person);
});

/** A one-hour regular limit keeps 8-hour rules fast to test; the limit is copied onto each shift at clock-in. */
function shortDay(): void
{
    app(Settings::class)->set('attendance.regular_limit_minutes', 60);
}

test('3.11.1 a web clock-in is an event from the browser device, written at server time', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:02')->assertRedirect('/')->assertSessionHasNoErrors();

    $event = AttendanceEvent::query()->sole();
    $cookie = $this->browser->last->getCookie(WebDevice::COOKIE);

    expect($this->browser->deviceId)->toStartWith('web:')
        ->and(strlen($this->browser->deviceId))->toBeLessThanOrEqual(64)
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addYears(4)->getTimestamp())
        ->and(Device::query()->find($this->browser->deviceId)->hostname)->toBe('Browser: Chrome, Windows')
        ->and($event->device_id)->toBe($this->browser->deviceId)
        ->and($event->offline)->toBeFalse()
        ->and($event->occurred_at->toIso8601ZuluString())->toBe('2026-09-14T02:02:00Z')
        ->and($event->occurred_at_device->toIso8601ZuluString())->toBe('2026-09-14T02:02:00Z')
        ->and($this->browser->shift()->flags)->toBe([]);
});

test('3.11.1 the same browser keeps one device across clock-ins', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $first = $this->browser->deviceId;
    $this->browser->post('/absen/pulang', at: '12:00');
    $this->browser->post('/absen/masuk', at: '13:00');

    expect($this->browser->deviceId)->toBe($first)
        ->and(Device::query()->where('id', 'like', 'web:%')->count())->toBe(1);
});

test('3.11.2 signing in on the web does not clock in', function () {
    $this->travelTo(Desk::time('2026-09-14 09:00'));

    $this->post(route('sign-in.store'), ['username' => $this->person->username, 'password' => 'password-for-tests'])->assertRedirect(route('my-day'));

    expect(Shift::query()->count())->toBe(0)
        ->and($this->browser->summary()['status'])->toBe('signed_out');
});

test('3.11.2 the web clock-in can be undone within 2 minutes', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');

    expect($this->browser->summary('09:01')['undo_until'])->toBe('2026-09-14T02:02:00.000Z');

    $this->browser->post('/absen/batal', at: '09:01:30')->assertSessionHasNoErrors();

    expect(Shift::query()->count())->toBe(0)
        ->and(AttendanceEvent::query()->pluck('type')->map->value->all())->toBe(['clock_in', 'clock_in_cancelled']);
});

test('3.11.2 an undo after 2 minutes is refused', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');

    $this->browser->post('/absen/batal', at: '09:02:10')->assertSessionHasErrors(['clock' => 'undo_expired']);

    expect($this->browser->summary()['undo_until'])->toBeNull()
        ->and(Shift::query()->count())->toBe(1);
});

test('3.11.2 on a non-workday the overtime reason is asked before the shift opens', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-19 10:00')->assertSessionHasErrors('reason');
    $this->browser->post('/absen/masuk', ['reason' => 'Render'])->assertSessionHasErrors('reason');

    expect(Shift::query()->count())->toBe(0);

    $this->browser->post('/absen/masuk', ['reason' => 'Revisi klien untuk Senin'])->assertSessionHasNoErrors();

    $summary = $this->browser->summary();

    expect($summary['status'])->toBe('overtime')
        ->and($summary['open_shift']['overtime']['reason'])->toBe('Revisi klien untuk Senin');
});

test('3.11.2 with 8 hours already reached today the 8-hour choice shows right away', function () {
    $desk = Desk::for($this, $this->person);
    $desk->send('clock_in', at: '2026-09-14 08:00');
    $desk->send('clock_out', at: '16:00');

    $this->browser->post('/absen/masuk', at: '19:00');
    $summary = $this->browser->summary();

    expect($summary['status'])->toBe('prompted')
        ->and($summary['prompt_deadline_at'])->toBe('2026-09-14T12:30:00.000Z')
        ->and($summary['open_shift']['on_this_browser'])->toBeTrue();
});

test('3.11.2 a shift open on a PC is moved to the browser only after asking, and back again from the PC', function () {
    $desk = Desk::for($this, $this->person, 'PC-ANIM-07');
    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->heartbeat('09:58');

    $this->browser->post('/absen/masuk', at: '10:00')->assertSessionHasErrors(['clock' => 'moved_elsewhere']);

    expect($this->browser->summary()['open_shift'])
        ->toMatchArray(['device_hostname' => 'PC-ANIM-07', 'on_this_browser' => false, 'is_web' => false]);

    $this->browser->post('/absen/pindah')->assertSessionHasNoErrors();

    expect($this->browser->summary()['open_shift']['on_this_browser'])->toBeTrue()
        ->and($desk->state()['shift']['device_id'])->toBe($this->browser->deviceId)
        ->and(Shift::query()->count())->toBe(1);

    $desk->send('shift_moved', at: '11:00')->assertJsonPath('shift.device_id', $desk->device->id);

    expect($this->browser->summary()['open_shift'])->toMatchArray(['on_this_browser' => false, 'device_hostname' => 'PC-ANIM-07'])
        ->and($this->browser->summary()['open_shift']['status'])->toBe('open');
});

test('3.11.3 heartbeats keep a web shift open; when they stop the shift is interrupted and a heartbeat does not continue it', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('09:20');

    expect($this->browser->summary('09:23')['status'])->toBe('open');

    // The tab was closed at 09:20
    $summary = $this->browser->summary('09:40');

    expect($summary['status'])->toBe('interrupted')
        ->and($summary['open_shift']['resume_until'])->toBe('2026-09-14T03:50:00.000Z');

    $this->browser->post('/absen/detak');
    expect($this->browser->summary()['status'])->toBe('interrupted');

    $this->browser->post('/absen/lanjut', at: '09:45')->assertSessionHasNoErrors();
    $this->browser->keepAlive('10:00');

    $open = $this->browser->summary()['shifts'][0];

    expect($open['status'])->toBe('open')
        ->and($open['interruption_minutes'])->toBe(25)
        ->and($open['regular_minutes'])->toBe(35);
});

test('3.11.3 any action on the browser continues an interrupted web shift and records the gap', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('09:20');

    $this->browser->post('/absen/pulang', at: '10:00')->assertSessionHasNoErrors();

    expect($this->browser->shift())
        ->status->toBe(ShiftStatus::Closed)
        ->interruption_minutes->toBe(40)
        ->regular_minutes->toBe(20);
});

test('3.11.3 without a return within 90 minutes the web shift is closed at the last heartbeat for review', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('09:20');

    expect($this->browser->summary('10:51')['status'])->toBe('signed_out');

    $this->browser->post('/absen/pulang')->assertSessionHasErrors(['clock' => 'no_open_shift']);
    $this->artisan('attendance:settle');

    expect($this->browser->shift())
        ->status->toBe(ShiftStatus::NeedsReview)
        ->end_reason->toBe(EndReason::ShutdownTimeout)
        ->and($this->browser->shift()->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T02:20:00Z');
});

test('3.11.4 a web shift has no idle periods', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('11:00');

    $summary = $this->browser->summary();

    expect($summary['idle_periods'])->toBe([])
        ->and($summary['open_shift']['is_web'])->toBeTrue()
        ->and($summary['shifts'][0]['idle_minutes'])->toBe(0);
});

test('3.11.5 an unanswered 8-hour prompt closes the web shift at the mark with no event from the browser', function () {
    shortDay();
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('10:28');

    expect($this->browser->summary()['status'])->toBe('prompted');

    $this->browser->keepAlive('10:32');
    $this->artisan('attendance:settle');

    expect($this->browser->shift())
        ->status->toBe(ShiftStatus::Closed)
        ->end_reason->toBe(EndReason::AutoNoAnswer)
        ->regular_minutes->toBe(60)
        ->and($this->browser->shift()->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T03:00:00Z')
        ->and(AttendanceEvent::query()->pluck('type')->map->value->all())->toBe(['clock_in']);
});

test('3.11.5 "Absen pulang" in the 8-hour prompt closes the shift at the click without overtime', function () {
    shortDay();
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('10:04');

    $this->browser->post('/absen/jawab-pulang', at: '10:05')->assertSessionHasNoErrors();

    expect($this->browser->shift())
        ->status->toBe(ShiftStatus::Closed)
        ->regular_minutes->toBe(60)
        ->overtime_minutes->toBe(0);
});

test('3.11.5 "Lanjut lembur" needs a reason of 10 characters and counts overtime from the mark', function () {
    shortDay();
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('10:04');

    $this->browser->post('/absen/lembur', ['reason' => 'Render'], '10:05')->assertSessionHasErrors('reason');
    $this->browser->post('/absen/lembur', ['reason' => 'Render final shot 12'])->assertSessionHasNoErrors();

    expect($this->browser->summary()['open_shift']['overtime'])
        ->toMatchArray(['started_at' => '2026-09-14T03:00:00.000Z', 'minutes' => 5, 'reason' => 'Render final shot 12']);
});

test('3.11.6 during web overtime "Masih lembur?" is due 60 minutes after overtime started', function () {
    shortDay();
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('10:04');
    $this->browser->post('/absen/lembur', ['reason' => 'Render final shot 12'], '10:05');
    $this->browser->keepAlive('10:40');

    expect($this->browser->summary()['presence_check'])->toBe([
        'next_check_at' => '2026-09-14T04:00:00.000Z',
        'check_shown_at' => null,
        'answer_deadline_at' => null,
    ]);

    $this->browser->keepAlive('11:10');

    expect($this->browser->summary()['presence_check'])->toBe([
        'next_check_at' => '2026-09-14T04:00:00.000Z',
        'check_shown_at' => '2026-09-14T04:00:00.000Z',
        'answer_deadline_at' => '2026-09-14T04:30:00.000Z',
    ]);
});

test('3.11.6 no answer to the web presence check ends overtime at the moment the check was shown', function () {
    shortDay();
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('10:04');
    $this->browser->post('/absen/lembur', ['reason' => 'Render final shot 12'], '10:05');
    $this->browser->keepAlive('11:28');

    expect($this->browser->summary()['status'])->toBe('overtime');

    $this->browser->keepAlive('11:32');
    $summary = $this->browser->summary();
    $this->artisan('attendance:settle');

    expect($summary['status'])->toBe('signed_out')
        ->and($summary['reports_due'][0]['overtime_minutes'])->toBe(60)
        ->and($summary['late_claims'][0]['overtime_end_reason'])->toBe('presence_check_no_answer')
        ->and($this->browser->shift())
        ->status->toBe(ShiftStatus::ReportDue)
        ->overtime_end_reason->toBe(OvertimeEndReason::PresenceCheckNoAnswer)
        ->and($this->browser->shift()->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T04:00:00Z');
});

test('3.11.6 answering "Masih lembur" starts the next 60 minutes', function () {
    shortDay();
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('10:04');
    $this->browser->post('/absen/lembur', ['reason' => 'Render final shot 12'], '10:05');
    $this->browser->keepAlive('11:08');

    $this->browser->post('/absen/masih-lembur', at: '11:10')->assertSessionHasNoErrors();
    $this->browser->keepAlive('11:40');

    $summary = $this->browser->summary();

    expect($summary['status'])->toBe('overtime')
        ->and($summary['presence_check']['next_check_at'])->toBe('2026-09-14T05:10:00.000Z')
        ->and($summary['open_shift']['overtime']['minutes'])->toBe(100);
});

test('3.11.7 clocking out of web overtime without a report leaves the report due until it is written on the web', function () {
    shortDay();
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('10:04');
    $this->browser->post('/absen/lembur', ['reason' => 'Render final shot 12'], '10:05');
    $this->browser->keepAlive('10:40');
    $this->browser->post('/absen/pulang', at: '10:45');

    $shift = $this->browser->shift();
    expect($shift->status)->toBe(ShiftStatus::ReportDue);

    $this->browser->post('/absen/laporan', ['shift_id' => $shift->id, 'work_report' => ''], '11:00')->assertSessionHasErrors('work_report');
    $this->browser->post('/absen/laporan', ['shift_id' => $shift->id, 'work_report' => 'Render shot 12 selesai'])->assertSessionHasNoErrors();

    expect($shift->refresh()->status)->toBe(ShiftStatus::Closed)
        ->and(OvertimeRequest::query()->sole()->work_report)->toBe('Render shot 12 selesai')
        ->and($this->browser->summary()['reports_due'])->toBe([]);
});

test('3.11.7 a work report due from the desktop app can be written on the web', function () {
    $desk = Desk::for($this, $this->person);
    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:02');
    $desk->send('pc_shutdown', at: '20:00');
    $shift = $desk->shift();

    $this->browser->at('2026-09-15 08:00');
    expect($this->browser->summary()['reports_due'][0]['shift_id'])->toBe($shift->id);

    $this->browser->post('/absen/laporan', ['shift_id' => $shift->id, 'work_report' => 'Render shot 12 dan 13'])->assertSessionHasNoErrors();

    expect(OvertimeRequest::query()->sole()->work_report)->toBe('Render shot 12 dan 13')
        ->and($this->browser->summary()['reports_due'])->toBe([]);
});

test('3.11.7 a late claim filed on the web is bounded by the last activity', function () {
    shortDay();
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('10:28');
    $this->browser->keepAlive('10:40');
    $shift = $this->browser->shift();

    $late = $this->browser->summary()['late_claims'][0];
    expect($late['latest_end_at'])->toBe('2026-09-14T03:28:00.000Z');

    $claim = ['shift_id' => $shift->id, 'reason' => 'Masih render, prompt tidak terlihat', 'work_report' => 'Render shot 7'];

    $this->browser->post('/absen/klaim', [...$claim, 'ended_at' => '2026-09-14 10:35'], '11:00')->assertSessionHasErrors(['ended_at' => 'after_last_activity']);
    $this->browser->post('/absen/klaim', [...$claim, 'ended_at' => '2026-09-14 10:25'])->assertSessionHasNoErrors();

    expect(OvertimeRequest::query()->sole())
        ->is_late_claim->toBeTrue()
        ->minutes->toBe(25);
});

test('3.11.8 with web clock-in turned off the page has no controls and the endpoints refuse', function () {
    app(Settings::class)->set('attendance.web_clock_in', false);

    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00')->assertForbidden();
    $this->browser->post('/absen/detak')->assertForbidden();
    $this->browser->post('/absen/laporan', ['shift_id' => 1, 'work_report' => 'Render shot 12'])->assertForbidden();
    $this->browser->post('/absen/klaim', ['shift_id' => 1])->assertForbidden();

    expect($this->browser->summary()['web_clock_in_enabled'])->toBeFalse()
        ->and(Shift::query()->count())->toBe(0);

    $this->actingAs($this->person)->get(route('my-day'))
        ->assertInertia(fn ($page) => $page->where('summary.web_clock_in_enabled', false));
});

test('3.11 only signed-in people who may clock in reach the web clock', function () {
    $noRole = new Browser($this, User::factory()->create());
    $noRole->post('/absen/masuk', at: '2026-09-14 09:00')->assertForbidden();

    app('auth')->forgetGuards();
    $this->app['auth']->guard('web')->logout();
    $this->post('/absen/masuk')->assertRedirect(route('sign-in'));

    expect(Shift::query()->count())->toBe(0)
        ->and($this->browser->summary()['web_clock_in_enabled'])->toBeTrue();
});

test('3.11 the web clock routes are in the web group and refuse a request without a CSRF token', function () {
    expect(Route::getRoutes()->getByName('web-clock.clock-in')->gatherMiddleware())->toContain('web');

    $middleware = new class(app(), app('encrypter')) extends ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    };

    $request = Request::create('/absen/masuk', 'POST');
    $request->setLaravelSession(app('session.store'));

    expect(fn () => $middleware->handle($request, fn () => response('ok')))->toThrow(TokenMismatchException::class);
});

test('3.11.2 answering no to the move question leaves the shift on the PC and records nothing', function () {
    $desk = Desk::for($this, $this->person, 'PC-ANIM-07');
    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->heartbeat('09:58');

    $this->browser->post('/absen/masuk', at: '10:00')->assertSessionHasErrors(['clock' => 'moved_elsewhere']);
    $desk->heartbeat('10:02');

    expect(AttendanceEvent::query()->pluck('type')->map->value->all())->toBe(['clock_in'])
        ->and($desk->state()['shift']['device_id'])->toBe($desk->device->id)
        ->and($this->browser->summary()['open_shift']['on_this_browser'])->toBeFalse();
});

test('3.11.3 a web shift counts as interrupted only after 240 seconds without a heartbeat, from the last one', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('09:20');

    expect($this->browser->summary('09:24:00')['status'])->toBe('open');

    $summary = $this->browser->summary('09:24:01');

    expect($summary['status'])->toBe('interrupted')
        ->and($summary['open_shift']['last_seen_at'])->toBe('2026-09-14T02:20:00.000Z')
        ->and($summary['open_shift']['resume_until'])->toBe('2026-09-14T03:50:00.000Z');
});

test('3.11.3 "Lanjutkan shift" after 90 minutes is refused and the shift is closed for review', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('09:20');

    $this->browser->post('/absen/lanjut', at: '10:51')->assertSessionHasErrors(['clock' => 'resume_expired']);

    expect($this->browser->summary()['status'])->toBe('signed_out')
        ->and(AttendanceEvent::query()->pluck('type')->map->value->all())->toBe(['clock_in']);
});

test('3.11.3 heartbeats from the browser stop counting once the shift runs on a PC', function () {
    $desk = Desk::for($this, $this->person, 'PC-ANIM-07');
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('09:10');
    $desk->send('shift_moved', at: '09:12');

    $this->browser->post('/absen/detak', at: '09:14');

    expect($this->browser->shift()->last_seen_at->toIso8601ZuluString())->toBe('2026-09-14T02:12:00Z')
        ->and($this->browser->summary()['open_shift']['on_this_browser'])->toBeFalse();
});

test('3.11.5 "Lanjut lembur" before the 8-hour mark is refused', function () {
    shortDay();
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('09:40');

    $this->browser->post('/absen/lembur', ['reason' => 'Render final shot 12'])->assertSessionHasErrors(['clock' => 'not_prompted']);

    $this->browser->keepAlive('10:04');

    expect(AttendanceEvent::query()->pluck('type')->map->value->all())->toBe(['clock_in'])
        ->and($this->browser->summary()['status'])->toBe('prompted');
});

test('3.11.6 "Masih lembur" outside overtime is refused', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('09:40');

    $this->browser->post('/absen/masih-lembur')->assertSessionHasErrors(['clock' => 'not_overtime']);

    expect(AttendanceEvent::query()->pluck('type')->map->value->all())->toBe(['clock_in']);
});

test('3.11.6 "Masih lembur?" is due 60 minutes after the shift moved to the browser', function () {
    shortDay();
    $desk = Desk::for($this, $this->person, 'PC-ANIM-07');
    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->send('overtime_start', ['reason' => 'Render final shot 12'], '10:02');
    $desk->heartbeat('10:20');

    $this->browser->post('/absen/pindah', at: '10:22')->assertSessionHasNoErrors();
    $this->browser->keepAlive('11:10');

    expect($this->browser->summary()['presence_check']['next_check_at'])->toBe('2026-09-14T04:22:00.000Z');

    $this->browser->keepAlive('11:53');
    $this->artisan('attendance:settle');

    expect($this->browser->shift())
        ->status->toBe(ShiftStatus::ReportDue)
        ->overtime_end_reason->toBe(OvertimeEndReason::PresenceCheckNoAnswer)
        ->and($this->browser->shift()->clock_out_at->toIso8601ZuluString())->toBe('2026-09-14T04:22:00Z');
});

test('3.11.6 a quiet period still open on the PC does not end overtime after the shift moved to the browser', function () {
    shortDay();
    $desk = Desk::for($this, $this->person, 'PC-ANIM-07');
    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->send('overtime_start', ['reason' => 'Render final shot 12'], '10:02');
    $desk->at('10:40')->sync([$desk->event('idle_start', at: '10:30')])->assertOk();

    // Left the PC at 10.30 and carried on from the phone at 10.45
    $this->browser->post('/absen/pindah', at: '10:45')->assertSessionHasNoErrors();
    $this->browser->keepAlive('12:05');

    $summary = $this->browser->summary();

    expect($summary['status'])->toBe('overtime')
        ->and($summary['presence_check']['check_shown_at'])->toBe('2026-09-14T04:45:00.000Z')
        ->and($summary['idle_periods'][0]['ended_at'])->toBe('2026-09-14T03:45:00.000Z');
});

test('3.11.7 the work report can be written right at the clock-out from web overtime', function () {
    shortDay();
    $this->browser->post('/absen/masuk', at: '2026-09-14 09:00');
    $this->browser->keepAlive('10:04');
    $this->browser->post('/absen/lembur', ['reason' => 'Render final shot 12'], '10:05');
    $this->browser->keepAlive('10:44');

    $this->browser->post('/absen/pulang', ['work_report' => 'Render shot 12 selesai'], '10:45')->assertSessionHasNoErrors();

    expect($this->browser->shift())
        ->status->toBe(ShiftStatus::Closed)
        ->overtime_minutes->toBe(45)
        ->and(OvertimeRequest::query()->sole()->work_report)->toBe('Render shot 12 selesai')
        ->and($this->browser->summary()['reports_due'])->toBe([]);
});

test('3.11.9 a person on a phone and a desktop PC at the same time has one shift that moves between them', function () {
    $desk = Desk::for($this, $this->person, 'PC-ANIM-07');
    $phone = new Browser($this, $this->person, Browser::SAFARI_IPHONE);

    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->heartbeat('09:58');

    $phone->post('/absen/masuk', at: '10:00')->assertSessionHasErrors(['clock' => 'moved_elsewhere']);
    $phone->post('/absen/pindah')->assertSessionHasNoErrors();

    expect(Device::query()->find($phone->deviceId)->hostname)->toBe('Browser: Safari, iOS')
        ->and($desk->heartbeat('10:02')->json('shift.device_id'))->toBe($phone->deviceId);

    // Only the browser the shift runs on can clock it out
    $phone->post('/absen/detak', at: '10:03');
    $this->browser->post('/absen/pulang')->assertSessionHasErrors(['clock' => 'moved_elsewhere']);
    $phone->keepAlive('12:00');
    $phone->post('/absen/pulang')->assertSessionHasNoErrors();

    expect(Shift::query()->sole())
        ->status->toBe(ShiftStatus::Closed)
        ->regular_minutes->toBe(180)
        ->and($desk->state()['shift'])->toBeNull();
});

test('3.11 a shift still running after midnight keeps its day total on Hari ini instead of showing 0', function () {
    $this->browser->post('/absen/masuk', at: '2026-09-14 20:00');
    $this->browser->keepAlive('2026-09-15 00:30');

    $summary = $this->browser->summary();

    expect($summary['date'])->toBe('2026-09-15')
        ->and($summary['status'])->toBe('open')
        ->and($summary['regular_minutes'])->toBe(270)
        ->and($summary['shifts'][0]['regular_minutes'])->toBe(270);
});
