<?php

use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Services\ShiftRecalculator;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Access\Role;
use App\Modules\Organization\Models\Team;
use App\Modules\Overtime\Actions\DecideOvertime;
use App\Modules\Overtime\Enums\Decision;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Overtime\Services\OvertimeApprovers;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Attendance\Support\Desk;

// Monday 2026-09-14 and Saturday 2026-09-19, Asia/Jakarta.

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->lead = userWithRole(Role::TeamLead);
    $this->manager = userWithRole(Role::ProjectManager);
    $this->director = userWithRole(Role::ProjectDirector);
    $this->admin = userWithRole(Role::Superadmin);

    $this->team = Team::factory()->create(['lead_user_id' => $this->lead->id]);
    $this->team->members()->attach([$this->person->id, $this->lead->id]);

    $this->desk = Desk::for($this, $this->person);
    $this->decide = app(DecideOvertime::class);
});

function workOvertime(Desk $desk, ?string $report = 'Render shot 12 selesai'): OvertimeRequest
{
    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:03');
    $desk->send('clock_out', $report !== null ? ['work_report' => $report] : [], '19:00');

    return OvertimeRequest::query()->where('user_id', $desk->user->id)->sole();
}

test('3.4.1 on a workday overtime runs from the 8-hour mark until clock-out', function () {
    $request = workOvertime($this->desk);

    expect($request->started_at->toIso8601ZuluString())->toBe('2026-09-14T10:00:00Z')
        ->and($request->ended_at->toIso8601ZuluString())->toBe('2026-09-14T12:00:00Z')
        ->and($request->minutes)->toBe(120)
        ->and($this->desk->shift()->regular_minutes)->toBe(480);
});

test('3.4.1 on a non-workday the whole shift is overtime', function () {
    $this->desk->send('clock_in', ['reason' => 'Revisi klien untuk Senin'], '2026-09-19 10:00');
    $this->desk->send('clock_out', ['work_report' => 'Revisi selesai'], '15:30');

    expect(OvertimeRequest::query()->sole()->minutes)->toBe(330)
        ->and($this->desk->shift()->regular_minutes)->toBe(0);
});

test('3.4.2 the shift stays report due until the work report is written, also at the next sign-in', function () {
    $request = workOvertime($this->desk, report: null);
    $shift = $this->desk->shift();

    expect($shift->status)->toBe(ShiftStatus::ReportDue)
        ->and($request->work_report)->toBeNull()
        ->and($request->submitted_at)->toBeNull();

    $this->desk->at('2026-09-15 09:00');
    $this->desk->send('clock_in')->assertJsonPath('reports_due.0.shift_id', $shift->id);
    $this->desk->send('overtime_report', ['work_report' => 'Render shot 12 selesai', 'shift_id' => $shift->id], '09:01')
        ->assertJsonPath('reports_due', []);

    expect($shift->refresh()->status)->toBe(ShiftStatus::Closed)
        ->and($request->refresh()->work_report)->toBe('Render shot 12 selesai')
        ->and($request->submitted_at->toIso8601ZuluString())->toBe('2026-09-15T02:01:00Z');
});

test('3.4.3 an overtime request is created as pending and routed to Management', function () {
    $request = workOvertime($this->desk);

    expect($request->status)->toBe(OvertimeStatus::Pending)
        ->and($request->reason)->toBe('Render final shot 12')
        ->and(app(OvertimeApprovers::class)->for($request)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->lead->id, $this->manager->id, $this->director->id])->sort()->values()->all());
});

test('3.4.3 an undecided request is removed when its overtime ends with 0 minutes (I16)', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:00:10');

    // Running overtime keeps its request while its minutes are still 0
    expect(OvertimeRequest::query()->sole())->ended_at->toBeNull()->minutes->toBe(0);

    $this->desk->send('clock_out', ['work_report' => 'Render ternyata sudah selesai'], '17:00:40');

    expect(OvertimeRequest::query()->exists())->toBeFalse()
        ->and($this->desk->shift()->overtime_minutes)->toBe(0);
});

test('3.4.5 a decided request keeps its history when its overtime is corrected to 0 minutes (I16)', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:00:20');
    $this->desk->send('clock_out', ['work_report' => 'Render shot 12 selesai'], '19:00');
    $request = OvertimeRequest::query()->sole();
    ($this->decide)($this->lead, $request, Decision::Approved);

    // A correction (3.10) moves the clock-out into the first minute of overtime
    $clockOut = Time::db(Desk::time('17:00:40'));
    AttendanceEvent::query()->where('type', 'clock_out')->update(['occurred_at' => $clockOut, 'occurred_at_device' => $clockOut]);
    app(ShiftRecalculator::class)->recalculateWorkDate($this->person->id, '2026-09-14', keepSavedRegular: false);

    expect($request->refresh())
        ->minutes->toBe(0)
        ->status->toBe(OvertimeStatus::Pending)
        ->work_report->toBe('Render shot 12 selesai')
        ->and($request->decisions()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'overtime.reset_to_pending')->count())->toBe(1);
});

test('3.4.4 the Team Lead, any Project Manager and any Project Director approve; nobody their own', function () {
    $request = workOvertime($this->desk);
    $approvers = app(OvertimeApprovers::class);
    $otherLead = userWithRole(Role::TeamLead);
    Team::factory()->create(['lead_user_id' => $otherLead->id]);

    expect($approvers->canApprove($this->lead, $request))->toBeTrue()
        ->and($approvers->canApprove($this->manager, $request))->toBeTrue()
        ->and($approvers->canApprove($this->director, $request))->toBeTrue()
        ->and($approvers->canApprove($otherLead, $request))->toBeFalse()
        ->and($approvers->canApprove($this->admin, $request))->toBeFalse()
        ->and($approvers->canApprove($this->person, $request))->toBeFalse();
});

test('3.4.4 a Team Lead\'s own request goes to Project Managers and Project Directors', function () {
    $leadDesk = Desk::for($this, $this->lead, 'PC-LEAD-01');
    $request = workOvertime($leadDesk);

    expect(app(OvertimeApprovers::class)->for($request)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->manager->id, $this->director->id])->sort()->values()->all());

    expect(fn () => ($this->decide)($this->lead, $request, Decision::Approved))->toThrow(AuthorizationException::class);
});

test('3.4.5 a decision is approved or rejected, with a note required when rejecting', function () {
    $request = workOvertime($this->desk);

    expect(fn () => ($this->decide)($this->lead, $request, Decision::Rejected))->toThrow(ValidationException::class);

    ($this->decide)($this->lead, $request, Decision::Rejected, 'Render bisa dijadwalkan besok pagi');

    expect($request->refresh()->status)->toBe(OvertimeStatus::Rejected)
        ->and($request->decisions()->sole()->note)->toBe('Render bisa dijadwalkan besok pagi');

    $second = OvertimeRequest::factory()->create();
    ($this->decide)($this->director, $second, Decision::Approved);

    expect($second->refresh()->status)->toBe(OvertimeStatus::Approved);
});

test('3.4.6 approval never blocks work', function () {
    $request = workOvertime($this->desk);
    ($this->decide)($this->lead, $request, Decision::Rejected, 'Tidak perlu lembur hari ini');

    $this->desk->send('clock_in', at: '2026-09-15 09:00')->assertJsonPath('shift.status', 'open');
    $this->desk->send('overtime_start', ['reason' => 'Render ulang shot 12'], '17:02')
        ->assertJsonPath('shift.status', 'overtime')
        ->assertJsonPath('shift.overtime.status', 'pending');

    $this->desk->heartbeat('18:30')->assertJsonPath('shift.overtime.minutes', 90);
});

test('3.4.5 decisions are made afterwards: not while overtime runs or the work report is missing', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:03');
    $request = OvertimeRequest::query()->sole();

    expect(fn () => ($this->decide)($this->lead, $request, Decision::Approved))->toThrow(ValidationException::class);

    $this->desk->send('clock_out', at: '19:00');

    expect(fn () => ($this->decide)($this->lead, $request->refresh(), Decision::Approved))->toThrow(ValidationException::class);

    $this->desk->send('overtime_report', ['work_report' => 'Render selesai'], '19:01');
    ($this->decide)($this->lead, $request->refresh(), Decision::Approved);

    expect($request->refresh()->status)->toBe(OvertimeStatus::Approved);
});

test('3.4.5 a decided request goes back to pending when its minutes change afterwards', function () {
    $this->desk->send('clock_in', at: '2026-09-14 09:00');
    $this->desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:02');
    $this->desk->at('18:10');
    $this->desk->sync([$this->desk->event('idle_start', at: '18:00')])->assertOk();
    $this->desk->heartbeat('19:40');
    $this->desk->send('overtime_report', ['work_report' => 'Render shot 12'], '19:41');
    $this->desk->send('clock_out', at: '21:00');

    $request = OvertimeRequest::query()->sole();
    expect($request->minutes)->toBe(60);
    ($this->decide)($this->lead, $request, Decision::Approved);

    $this->desk->at('2026-09-15 08:00');
    $this->desk->request('POST', "/api/v1/overtime/{$this->desk->shift()->id}/claim", [
        'reason' => 'Masih di meja, cek render tiap jam',
        'work_report' => 'Render shot 12 dan 13 selesai',
        'ended_at' => '2026-09-14T14:00:00Z',
    ])->assertCreated()->assertJsonPath('overtime_request.status', 'pending');

    $audit = AuditLog::query()->where('action', 'overtime.reset_to_pending')->sole();

    expect($request->refresh())
        ->status->toBe(OvertimeStatus::Pending)
        ->minutes->toBe(240)
        ->and($audit->before)->toEqual(['status' => 'approved', 'minutes' => 60])
        ->and($audit->after)->toEqual(['status' => 'pending', 'minutes' => 240, 'reason' => 'minutes_changed']);
});

test('3.4.7 a decision is changed only by a Project Director or Superadmin, with a note, and audited', function () {
    $request = workOvertime($this->desk);
    ($this->decide)($this->lead, $request, Decision::Rejected, 'Tidak ada permintaan klien');

    expect(fn () => ($this->decide)($this->manager, $request, Decision::Approved, 'Ternyata diminta klien'))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->decide)($this->director, $request, Decision::Approved))->toThrow(ValidationException::class);

    ($this->decide)($this->director, $request, Decision::Approved, 'Ternyata diminta klien');
    ($this->decide)($this->admin, $request, Decision::Rejected, 'Dikoreksi HR');

    expect($request->refresh()->status)->toBe(OvertimeStatus::Rejected)
        ->and($request->decisions()->pluck('decided_by')->all())->toBe([$this->lead->id, $this->director->id, $this->admin->id])
        ->and(AuditLog::query()->where('subject_id', $request->id)->pluck('action')->all())
        ->toBe(['overtime.decided', 'overtime.decision_changed', 'overtime.decision_changed']);
});

test('3.4.8 on a non-workday the reason is asked at sign-in and the report at clock-out', function () {
    $this->desk->send('clock_in', ['reason' => 'Revisi klien untuk Senin'], '2026-09-19 10:00');
    $this->desk->send('clock_out', ['work_report' => 'Revisi warna shot 3 selesai'], '13:00');

    $request = OvertimeRequest::query()->sole();

    expect($request->reason)->toBe('Revisi klien untuk Senin')
        ->and($request->work_report)->toBe('Revisi warna shot 3 selesai')
        ->and($request->started_at->toIso8601ZuluString())->toBe('2026-09-19T03:00:00Z')
        ->and($this->desk->shift()->status)->toBe(ShiftStatus::Closed);
});
