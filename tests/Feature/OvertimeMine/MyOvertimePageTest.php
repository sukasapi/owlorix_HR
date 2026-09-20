<?php

use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use App\Modules\Overtime\Actions\DecideOvertime;
use App\Modules\Overtime\Enums\Decision;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Attendance\Support\Desk;

// Monday 2026-09-14, Saturday 2026-09-19, Monday 2026-10-05 (Asia/Jakarta).

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->desk = Desk::for($this, $this->person);
    $this->director = userWithRole(Role::ProjectDirector);
    $this->decide = app(DecideOvertime::class);
});

function overtimeOn(Desk $desk, string $date, ?string $report = 'Render shot 12 selesai', string $until = '19:00'): OvertimeRequest
{
    $desk->send('clock_in', at: "{$date} 09:00");
    $desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:03');
    $desk->send('clock_out', $report !== null ? ['work_report' => $report] : [], $until);

    return OvertimeRequest::query()->where('shift_id', $desk->shift()->id)->sole();
}

it('sends a guest to sign in', function () {
    $this->get(route('overtime.mine'))->assertRedirect(route('sign-in'));
});

it('refuses an account that cannot clock in', function () {
    $this->actingAs(User::factory()->create())->get(route('overtime.mine'))->assertForbidden();
});

it('shows Lembur in the navigation and an empty list before any overtime', function () {
    $this->travelTo(Desk::time('2026-09-14 08:00'));

    $this->actingAs($this->person)
        ->get(route('overtime.mine'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('overtime/Index')
            ->where('has_requests', false)
            ->where('months', [])
            ->where('filters', ['status' => 'all', 'bulan' => null])
            ->has('requests.data', 0)
            ->has('late_claims', 0)
            ->where('web_clock_in_enabled', true)
            ->where('nav', fn ($nav) => collect($nav)
                ->flatMap(fn ($group) => $group['items'])
                ->contains(fn ($item) => $item['key'] === 'overtime' && $item['href'] === '/lembur')));

    app(Settings::class)->set('attendance.web_clock_in', false);

    $this->actingAs($this->person)
        ->get(route('overtime.mine'))
        ->assertInertia(fn ($page) => $page->where('web_clock_in_enabled', false));
});

it('lists only the person\'s own requests, newest work date first, with reason, report, and status', function () {
    overtimeOn($this->desk, '2026-09-14');
    overtimeOn($this->desk, '2026-09-15', report: null, until: '18:00');
    $this->desk->send('clock_in', ['reason' => 'Revisi klien untuk Senin'], '2026-09-19 10:00');
    $this->desk->send('clock_out', ['work_report' => 'Revisi warna shot 3 selesai'], '13:00');

    $other = userWithRole(Role::Employee);
    $theirs = overtimeOn(Desk::for($this, $other, 'PC-ANIM-09'), '2026-09-16');

    $this->actingAs($this->person)
        ->get(route('overtime.mine', ['user' => $other->id]))
        ->assertInertia(fn ($page) => $page
            ->where('has_requests', true)
            ->where('months', ['2026-09'])
            ->where('requests.total', 3)
            ->where('requests.data', fn ($items) => ! collect($items)->pluck('id')->contains($theirs->id))
            ->where('requests.data.0.work_date', '2026-09-19')
            ->where('requests.data.0.started_at', '2026-09-19T03:00:00.000Z')
            ->where('requests.data.0.ended_at', '2026-09-19T06:00:00.000Z')
            ->where('requests.data.0.minutes', 180)
            ->where('requests.data.0.reason', 'Revisi klien untuk Senin')
            ->where('requests.data.0.work_report', 'Revisi warna shot 3 selesai')
            ->where('requests.data.0.status', 'pending')
            ->where('requests.data.0.decisions', [])
            ->where('requests.data.1.work_date', '2026-09-15')
            ->where('requests.data.1.minutes', 60)
            ->where('requests.data.1.work_report', null)
            ->where('requests.data.1.report_due', true)
            ->where('requests.data.1.is_running', false)
            ->where('requests.data.2.work_date', '2026-09-14')
            ->where('requests.data.2.report_due', false)
            ->where('requests.data.2.is_late_claim', false));

    $this->actingAs($other)
        ->get(route('overtime.mine'))
        ->assertInertia(fn ($page) => $page
            ->where('requests.total', 1)
            ->where('requests.data.0.id', $theirs->id));
});

it('filters by status and by month', function () {
    ($this->decide)($this->director, overtimeOn($this->desk, '2026-09-14'), Decision::Approved);
    overtimeOn($this->desk, '2026-10-05');

    $get = fn (array $query) => $this->actingAs($this->person)->get(route('overtime.mine', $query));

    $get(['status' => 'approved'])->assertInertia(fn ($page) => $page
        ->where('filters.status', 'approved')
        ->where('requests.total', 1)
        ->where('requests.data.0.work_date', '2026-09-14'));

    $get(['status' => 'pending'])->assertInertia(fn ($page) => $page
        ->where('requests.total', 1)
        ->where('requests.data.0.work_date', '2026-10-05'));

    $get(['bulan' => '2026-10'])->assertInertia(fn ($page) => $page
        ->where('filters.bulan', '2026-10')
        ->where('months', ['2026-10', '2026-09'])
        ->where('requests.total', 1)
        ->where('requests.data.0.work_date', '2026-10-05'));

    $get(['status' => 'approved', 'bulan' => '2026-10'])->assertInertia(fn ($page) => $page
        ->where('has_requests', true)
        ->has('requests.data', 0));

    $get(['bulan' => '2026-03'])->assertInertia(fn ($page) => $page
        ->where('months', ['2026-10', '2026-09', '2026-03'])
        ->has('requests.data', 0));

    $get(['status' => 'everything', 'bulan' => 'oktober'])->assertInertia(fn ($page) => $page
        ->where('filters', ['status' => 'all', 'bulan' => null])
        ->where('requests.total', 2)
        ->where('requests.data.0.work_date', '2026-10-05'));
});

it('pages through requests 15 at a time and keeps the filters in the page links', function () {
    foreach (range(1, 17) as $day) {
        $date = sprintf('2026-08-%02d', $day);
        $clockIn = CarbonImmutable::parse("{$date} 09:00", 'Asia/Jakarta')->utc();
        $shift = Shift::factory()->create([
            'user_id' => $this->person->id,
            'work_date' => $date,
            'clock_in_at' => $clockIn,
            'regular_ends_at' => $clockIn->addMinutes(480),
            'clock_out_at' => $clockIn->addMinutes(600),
            'last_seen_at' => $clockIn->addMinutes(600),
        ]);
        OvertimeRequest::factory()->create(['shift_id' => $shift->id]);
    }
    $this->travelTo(Desk::time('2026-09-14 08:00'));

    $this->actingAs($this->person)
        ->get(route('overtime.mine', ['status' => 'pending']))
        ->assertInertia(fn ($page) => $page
            ->has('requests.data', 15)
            ->where('requests.total', 17)
            ->where('requests.last_page', 2)
            ->where('requests.data.0.work_date', '2026-08-17')
            ->where('requests.next_page_url', fn ($url) => str_contains($url, 'status=pending') && str_contains($url, 'page=2')));

    $this->actingAs($this->person)
        ->get(route('overtime.mine', ['status' => 'pending', 'page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->has('requests.data', 2)
            ->where('requests.data.1.work_date', '2026-08-01'));
});

it('shows the decision history: who decided, when, and the note', function () {
    $lead = userWithRole(Role::TeamLead);
    Team::factory()->create(['lead_user_id' => $lead->id])->members()->attach([$this->person->id, $lead->id]);
    $request = overtimeOn($this->desk, '2026-09-14');

    $this->travelTo(Desk::time('2026-09-15 10:14'));
    ($this->decide)($lead, $request, Decision::Rejected, 'Render bisa dijadwalkan besok pagi');
    $this->travelTo(Desk::time('2026-09-15 13:30'));
    ($this->decide)($this->director, $request->refresh(), Decision::Approved, 'Klien memang minta hari itu');

    $this->actingAs($this->person)
        ->get(route('overtime.mine'))
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.status', 'approved')
            ->where('requests.data.0.was_reset', false)
            ->where('requests.data.0.decisions', [
                ['decision' => 'rejected', 'note' => 'Render bisa dijadwalkan besok pagi', 'decided_at' => '2026-09-15T03:14:00.000Z', 'decided_by' => $lead->name],
                ['decision' => 'approved', 'note' => 'Klien memang minta hari itu', 'decided_at' => '2026-09-15T06:30:00.000Z', 'decided_by' => $this->director->name],
            ]));
});

it('shows a late claim that is still possible with its deadline, then the filed claim as a late claim', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('17:04');
    $this->desk->send('pc_shutdown', at: '17:05');
    $shiftId = $this->desk->shift()->id;
    $this->desk->at('2026-09-15 08:00');

    $this->actingAs($this->person)
        ->get(route('overtime.mine'))
        ->assertInertia(fn ($page) => $page
            ->where('has_requests', false)
            ->where('late_claims', [[
                'shift_id' => $shiftId,
                'work_date' => '2026-09-14',
                'clock_in_at' => '2026-09-14T02:00:00.000Z',
                'auto_ended_at' => '2026-09-14T10:00:00.000Z',
                'cause' => 'prompt',
                'claimable_until' => '2026-09-15T10:00:00.000Z',
                'latest_end_at' => '2026-09-14T10:05:00.000Z',
            ]]));

    $this->desk->request('POST', "/api/v1/overtime/{$shiftId}/claim", [
        'reason' => 'Masih render, prompt tidak terlihat',
        'work_report' => 'Render shot 7',
        'ended_at' => '2026-09-14T10:05:00Z',
    ])->assertCreated();

    $this->actingAs($this->person)
        ->get(route('overtime.mine'))
        ->assertInertia(fn ($page) => $page
            ->has('late_claims', 0)
            ->where('requests.data.0.is_late_claim', true)
            ->where('requests.data.0.minutes', 5)
            ->where('requests.data.0.status', 'pending'));
});

it('drops the late claim once its window has passed', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->heartbeat('17:40');
    $this->travelTo(Desk::time('2026-09-15 17:01'));

    $this->actingAs($this->person)
        ->get(route('overtime.mine'))
        ->assertInertia(fn ($page) => $page->has('late_claims', 0));
});

it('marks a decided request that went back to pending because its minutes changed', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:02');
    $this->desk->at('18:10');
    $this->desk->sync([$this->desk->event('idle_start', at: '18:00')])->assertOk();
    $this->desk->heartbeat('19:40');
    $this->desk->send('overtime_report', ['work_report' => 'Render shot 12'], '19:41');
    $this->desk->send('clock_out', at: '21:00');
    ($this->decide)($this->director, OvertimeRequest::query()->sole(), Decision::Approved);

    $this->desk->at('2026-09-15 08:00');
    $this->desk->request('POST', "/api/v1/overtime/{$this->desk->shift()->id}/claim", [
        'reason' => 'Masih di meja, cek render tiap jam',
        'work_report' => 'Render shot 12 dan 13 selesai',
        'ended_at' => '2026-09-14T14:00:00Z',
    ])->assertCreated();

    $this->actingAs($this->person)
        ->get(route('overtime.mine'))
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.status', 'pending')
            ->where('requests.data.0.was_reset', true)
            ->where('requests.data.0.minutes', 240)
            ->has('requests.data.0.decisions', 1));
});

it('sends the rule values the page quotes, so the copy follows the settings', function () {
    $this->travelTo(Desk::time('2026-09-14 08:00'));
    app(Settings::class)->set('attendance.prompt_auto_close_minutes', 45);

    $this->actingAs($this->person)
        ->get(route('overtime.mine'))
        ->assertInertia(fn ($page) => $page->where('rules', [
            'regular_limit_minutes' => 480,
            'prompt_auto_close_minutes' => 45,
            'overtime_idle_answer_minutes' => 30,
            'late_claim_hours' => 24,
        ]));
});

it('reads a page of requests with the same number of queries however many requests it holds', function () {
    $lead = userWithRole(Role::TeamLead);
    Team::factory()->create(['lead_user_id' => $lead->id])->members()->attach([$this->person->id, $lead->id]);

    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->person)->get(route('overtime.mine'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $decided = function (string $date) use ($lead) {
        $request = overtimeOn($this->desk, $date);
        ($this->decide)($lead, $request, Decision::Rejected, 'Render bisa dijadwalkan besok pagi');
        ($this->decide)($this->director, $request->refresh(), Decision::Approved, 'Klien memang minta hari itu');
    };

    $decided('2026-09-14');
    $this->travelTo(Desk::time('2026-09-30 20:00'));
    $queries();
    $few = $queries();

    foreach (['2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24'] as $date) {
        $decided($date);
    }
    $this->travelTo(Desk::time('2026-09-30 20:00'));

    expect($queries())->toBe($few)
        ->and($few)->toBeLessThan(30);
});

it('shows running overtime with the minutes up to now', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:03');
    $this->desk->heartbeat('18:28');
    $this->travelTo(Desk::time('2026-09-14 18:30'));

    $this->actingAs($this->person)
        ->get(route('overtime.mine'))
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.is_running', true)
            ->where('requests.data.0.ended_at', null)
            ->where('requests.data.0.report_due', false)
            ->where('requests.data.0.minutes', 90));
});
