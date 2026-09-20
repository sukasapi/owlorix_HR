<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Overtime\Actions\DecideOvertime;
use App\Modules\Overtime\Enums\Decision;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\WebClock\Support\Browser;

// September 2026 in Asia/Jakarta: Monday 14, Saturday 19, Wednesday 30. 1 October is a Thursday.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->desk = Desk::for($this, $this->person);
});

it('sends a guest to sign in', function () {
    $this->get(route('history'))->assertRedirect(route('sign-in'));
});

it('refuses an account that cannot clock in', function () {
    $this->actingAs(User::factory()->create())->get(route('history'))->assertForbidden();
});

it('shows Riwayat in the navigation of someone who clocks in', function () {
    $this->travelTo(Desk::time('2026-09-14 08:00'));

    $this->actingAs($this->person)
        ->get(route('history'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('history/Index')
            ->where('web_clock_in_enabled', true)
            ->where('nav', fn ($nav) => collect($nav)
                ->flatMap(fn ($group) => $group['items'])
                ->contains(fn ($item) => $item['key'] === 'history' && $item['href'] === '/riwayat')));

    app(Settings::class)->set('attendance.web_clock_in', false);

    $this->actingAs($this->person)
        ->get(route('history'))
        ->assertInertia(fn ($page) => $page->where('web_clock_in_enabled', false));
});

it('groups shifts by the Jakarta date of clock-in, also for a shift that crosses midnight', function () {
    $this->desk->send('clock_in', at: '2026-09-30 20:00');
    $this->desk->send('clock_out', at: '2026-10-01 02:00');
    $this->desk->send('clock_in', at: '2026-10-01 10:00');
    $this->desk->send('clock_out', at: '12:00');

    $this->actingAs($this->person)
        ->get(route('history', ['bulan' => '2026-09']))
        ->assertInertia(fn ($page) => $page
            ->where('month.value', '2026-09')
            ->where('month.previous', '2026-08')
            ->where('month.next', '2026-10')
            ->where('month.current', '2026-10')
            ->has('days', 30)
            ->where('days.29.date', '2026-09-30')
            ->where('days.29.weekday', 3)
            ->has('days.29.shifts', 1)
            ->where('days.29.shifts.0.work_date', '2026-09-30')
            ->where('days.29.shifts.0.clock_in_at', '2026-09-30T13:00:00.000Z')
            ->where('days.29.shifts.0.clock_out_at', '2026-09-30T19:00:00.000Z')
            ->where('days.29.regular_minutes', 360)
            ->where('totals.days_worked', 1)
            ->where('totals.regular_minutes', 360));

    $this->actingAs($this->person)
        ->get(route('history'))
        ->assertInertia(fn ($page) => $page
            ->where('month.value', '2026-10')
            ->where('month.next', null)
            ->where('today', '2026-10-01')
            ->where('days.0.is_today', true)
            ->has('days.0.shifts', 1)
            ->where('days.0.shifts.0.clock_in_at', '2026-10-01T03:00:00.000Z')
            ->where('days.1.is_future', true)
            ->where('totals.days_worked', 1)
            ->where('totals.regular_minutes', 120));
});

it('adds up the month: regular time, overtime by decision, quiet PC time, short days, and non-workdays', function () {
    $director = userWithRole(Role::ProjectDirector);
    $decide = app(DecideOvertime::class);

    // Monday: 8 hours, 40 quiet minutes, 2 hours of overtime that gets approved
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->at('10:20');
    $this->desk->sync([$this->desk->event('idle_start', at: '10:10')])->assertOk();
    $this->desk->send('idle_end', at: '10:50');
    $this->desk->send('idle_tag', ['tag' => 'rendering'], '10:51');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:03');
    $this->desk->send('clock_out', ['work_report' => 'Render shot 12 selesai'], '19:00');

    // Tuesday: 8 hours and 1 hour of overtime that gets rejected
    $this->desk->send('clock_in', at: '2026-09-15 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Revisi warna shot 3'], '17:02');
    $this->desk->send('clock_out', ['work_report' => 'Revisi warna selesai'], '18:00');

    // Wednesday: a short day
    $this->desk->send('clock_in', at: '2026-09-16 09:00');
    $this->desk->send('clock_out', at: '15:00');

    // Saturday, not a workday: all overtime, still waiting
    $this->desk->send('clock_in', ['reason' => 'Revisi klien untuk Senin'], '2026-09-19 10:00');
    $this->desk->send('clock_out', ['work_report' => 'Revisi selesai'], '13:00');

    [$monday, $tuesday] = OvertimeRequest::query()->orderBy('started_at')->get()->all();
    $decide($director, $monday, Decision::Approved);
    $decide($director, $tuesday, Decision::Rejected, 'Revisi bisa menunggu Rabu pagi');

    $this->actingAs($this->person)
        ->get(route('history', ['bulan' => '2026-09']))
        ->assertInertia(fn ($page) => $page
            ->where('totals.days_worked', 4)
            ->where('totals.regular_minutes', 480 + 480 + 360)
            ->where('totals.overtime_minutes', 120 + 60 + 180)
            ->where('totals.overtime_approved_minutes', 120)
            ->where('totals.overtime_rejected_minutes', 60)
            ->where('totals.overtime_pending_minutes', 180)
            ->where('totals.idle_minutes', 40)
            ->where('totals.short_days', 1)
            ->where('totals.non_workdays_worked', 1)
            ->where('totals.has_live_shift', false)
            ->where('days.13.idle_minutes', 40)
            ->where('days.13.shifts.0.idle_periods.0.tag', 'rendering')
            ->where('days.13.shifts.0.overtime.status', 'approved')
            ->where('days.13.shifts.0.overtime.reason', 'Render final shot 12')
            ->where('days.13.shifts.0.overtime.work_report', 'Render shot 12 selesai')
            ->where('days.13.shifts.0.overtime.decisions.0.decision', 'approved')
            ->where('days.13.shifts.0.overtime.decisions.0.decided_by', $director->name)
            ->where('days.14.shifts.0.overtime.status', 'rejected')
            ->where('days.14.shifts.0.overtime.decisions.0.note', 'Revisi bisa menunggu Rabu pagi')
            ->where('days.15.is_short', true)
            ->where('days.15.shifts.0.is_short', true)
            ->where('days.15.overtime_minutes', 0)
            ->where('days.18.is_workday', false)
            ->where('days.18.calendar.is_workday', false)
            ->where('days.18.shifts.0.overtime.status', 'pending')
            ->where('days.18.regular_minutes', 0)
            ->has('days.19.shifts', 0));
});

it('never shows another person\'s shifts', function () {
    $other = userWithRole(Role::Employee);
    $otherDesk = Desk::for($this, $other, 'PC-ANIM-09');

    $otherDesk->send('clock_in', at: '2026-09-14 08:00');
    $otherDesk->send('clock_out', at: '12:00');
    $this->desk->send('clock_in', at: '2026-09-15 09:00');
    $this->desk->send('clock_out', at: '11:00');

    $this->actingAs($this->person)
        ->get(route('history', ['bulan' => '2026-09', 'user' => $other->id, 'user_id' => $other->id]))
        ->assertInertia(fn ($page) => $page
            ->has('days.13.shifts', 0)
            ->has('days.14.shifts', 1)
            ->where('days.14.shifts.0.id', $this->desk->shift()->id)
            ->where('totals.days_worked', 1)
            ->where('totals.regular_minutes', 120));

    $this->actingAs($other)
        ->get(route('history', ['bulan' => '2026-09']))
        ->assertInertia(fn ($page) => $page
            ->has('days.13.shifts', 1)
            ->has('days.14.shifts', 0)
            ->where('totals.regular_minutes', 240));
});

it('shows a shift the time rules ended before cron saved it, with its late claim window', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('17:04');
    $this->desk->send('pc_shutdown', at: '17:05');
    $this->travelTo(Desk::time('2026-09-15 08:00'));

    $this->actingAs($this->person)
        ->get(route('history', ['bulan' => '2026-09']))
        ->assertInertia(fn ($page) => $page
            ->where('days.13.shifts.0.is_live', false)
            ->where('days.13.shifts.0.end_reason', 'auto_no_answer')
            ->where('days.13.shifts.0.clock_out_at', '2026-09-14T10:00:00.000Z')
            ->where('days.13.shifts.0.late_claim.claimable_until', '2026-09-15T10:00:00.000Z')
            ->where('days.13.shifts.0.late_claim.latest_end_at', '2026-09-14T10:05:00.000Z')
            ->where('days.13.regular_minutes', 480));
});

it('counts a running shift and says so', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('11:30');

    $this->actingAs($this->person)
        ->get(route('history'))
        ->assertInertia(fn ($page) => $page
            ->where('days.13.shifts.0.is_live', true)
            ->where('days.13.shifts.0.clock_out_at', null)
            ->where('totals.regular_minutes', 150)
            ->where('totals.has_live_shift', true));
});

it('names the device of each shift and its moves, and marks browser time as having no idle detection', function () {
    $browser = new Browser($this, $this->person);

    // Monday: clocked in on the studio PC, moved to the browser at 12.00, clocked out there
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('11:58');
    $browser->post('/absen/pindah', at: '12:00')->assertSessionHasNoErrors();
    $browser->keepAlive('13:00');
    $browser->post('/absen/pulang')->assertSessionHasNoErrors();

    // Tuesday: only the browser
    $browser->post('/absen/masuk', at: '2026-09-15 09:00')->assertSessionHasNoErrors();
    $browser->keepAlive('10:00');
    $browser->post('/absen/pulang')->assertSessionHasNoErrors();

    $this->actingAs($this->person)
        ->get(route('history', ['bulan' => '2026-09']))
        ->assertInertia(fn ($page) => $page
            ->where('days.13.shifts.0.devices', [
                ['at' => '2026-09-14T02:00:00.000Z', 'device_id' => $this->desk->device->id, 'name' => 'PC-ANIM-07', 'is_web' => false],
                ['at' => '2026-09-14T05:00:00.000Z', 'device_id' => $browser->deviceId, 'name' => 'Browser: Chrome, Windows', 'is_web' => true],
            ])
            ->where('days.13.shifts.0.idle_detection', 'partial')
            ->where('days.13.regular_minutes', 240)
            ->where('days.14.shifts.0.devices', [
                ['at' => '2026-09-15T02:00:00.000Z', 'device_id' => $browser->deviceId, 'name' => 'Browser: Chrome, Windows', 'is_web' => true],
            ])
            ->where('days.14.shifts.0.idle_detection', 'none')
            ->where('days.14.shifts.0.idle_periods', [])
            ->where('totals.has_web_shifts', true));
});

it('marks a month with only desktop shifts as having idle detection throughout', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('clock_out', at: '12:00');

    $this->actingAs($this->person)
        ->get(route('history', ['bulan' => '2026-09']))
        ->assertInertia(fn ($page) => $page
            ->where('days.13.shifts.0.idle_detection', 'full')
            ->has('days.13.shifts.0.devices', 1)
            ->where('totals.has_web_shifts', false));
});

it('sends the rule values the page quotes, so the copy follows the settings', function () {
    $this->travelTo(Desk::time('2026-09-14 08:00'));
    app(Settings::class)->set('attendance.regular_limit_minutes', 420);
    app(Settings::class)->set('attendance.overtime_idle_answer_minutes', 20);

    $this->actingAs($this->person)
        ->get(route('history'))
        ->assertInertia(fn ($page) => $page->where('rules', [
            'regular_limit_minutes' => 420,
            'prompt_auto_close_minutes' => 30,
            'overtime_idle_answer_minutes' => 20,
            'resume_window_minutes' => 90,
            'late_claim_hours' => 24,
        ]));
});

it('reads a month with the same number of queries however many dates have shifts', function () {
    $director = userWithRole(Role::ProjectDirector);
    $decide = app(DecideOvertime::class);

    $workDay = function (string $date) use ($director, $decide) {
        $this->desk->send('clock_in', at: "{$date} 09:00");
        $this->desk->at("{$date} 10:20");
        $this->desk->sync([$this->desk->event('idle_start', at: "{$date} 10:10")])->assertOk();
        $this->desk->send('idle_end', at: "{$date} 10:50");
        $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], "{$date} 17:03");
        $this->desk->send('clock_out', ['work_report' => 'Render shot 12 selesai'], "{$date} 19:00");
        $decide($director, OvertimeRequest::query()->where('shift_id', $this->desk->shift()->id)->sole(), Decision::Rejected, 'Bisa besok pagi');
    };

    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->person)->get(route('history', ['bulan' => '2026-09']))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $workDay('2026-09-14');
    $workDay('2026-09-15');
    $this->travelTo(Desk::time('2026-09-30 20:00'));
    $queries();
    $few = $queries();

    foreach (['2026-09-16', '2026-09-17', '2026-09-18', '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'] as $date) {
        $workDay($date);
    }
    $this->travelTo(Desk::time('2026-09-30 20:00'));

    expect($queries())->toBe($few)
        ->and($few)->toBeLessThan(40);
});

it('falls back to the current studio month when the month is not YYYY-MM', function (string $value) {
    // 18.30 UTC on 30 September is already 1 October in Jakarta
    $this->travelTo(CarbonImmutable::parse('2026-09-30 18:30:00', 'UTC'));

    $this->actingAs($this->person)
        ->get(route('history', ['bulan' => $value]))
        ->assertInertia(fn ($page) => $page->where('month.value', '2026-10')->has('days', 31));
})->with(['2026-13', 'september', '2026-9', '']);
