<?php

use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use App\Modules\Overtime\Actions\DecideOvertime;
use App\Modules\Overtime\Enums\Decision;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Overtime\Services\OvertimeApprovers;
use App\Modules\Overtime\Services\PendingApprovals;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Attendance\Support\Desk;
use Tests\TestCase;

// Monday 2026-09-14, Asia/Jakarta.

beforeEach(function () {
    $this->lead = userWithRole(Role::TeamLead);
    $this->member = userWithRole(Role::Employee);
    $this->memberTwo = userWithRole(Role::Employee);
    $this->outsider = userWithRole(Role::Employee);
    $this->otherLead = userWithRole(Role::TeamLead);
    $this->manager = userWithRole(Role::ProjectManager);
    $this->director = userWithRole(Role::ProjectDirector);
    $this->admin = userWithRole(Role::Superadmin);

    $this->team = Team::factory()->create(['name' => 'Animation', 'lead_user_id' => $this->lead->id]);
    $this->team->members()->attach([$this->lead->id, $this->member->id, $this->memberTwo->id, $this->otherLead->id]);
    $other = Team::factory()->create(['name' => 'Lighting', 'lead_user_id' => $this->otherLead->id]);
    $other->members()->attach([$this->otherLead->id, $this->outsider->id]);
});

/** A finished overtime request with its report, as SyncOvertimeRequest leaves it. */
function approvalsRequestFor(User $person, array $request = [], array $shift = []): OvertimeRequest
{
    return OvertimeRequest::factory()->create([
        'shift_id' => Shift::factory()->create(['user_id' => $person->id, ...$shift])->id,
        ...$request,
    ]);
}

/** @return list<int> */
function approvalsPendingIds(TestCase $test, User $viewer): array
{
    return collect($test->actingAs($viewer)->get(route('approvals.index'))->assertOk()->viewData('page')['props']['pending'])
        ->pluck('id')->sort()->values()->all();
}

function approvalsDecide(TestCase $test, User $actor, OvertimeRequest $request, array $data = []): TestResponse
{
    $request->refresh();

    return $test->actingAs($actor)->from(route('approvals.index'))->post(route('approvals.decide', $request), [
        'decision' => 'approved',
        'seen_status' => $request->status->value,
        'seen_minutes' => $request->minutes,
        'seen_decision_id' => $request->latestDecision()->value('id'),
        ...$data,
    ]);
}

test('a Team Lead sees pending overtime of their own team only, never their own or another Team Lead\'s', function () {
    $mine = approvalsRequestFor($this->member);
    $mineToo = approvalsRequestFor($this->memberTwo);
    approvalsRequestFor($this->outsider);
    approvalsRequestFor($this->lead);
    approvalsRequestFor($this->otherLead);

    expect(approvalsPendingIds($this, $this->lead))->toBe([$mine->id, $mineToo->id]);

    // The SQL scope agrees with the rule DecideOvertime uses
    $approvers = app(OvertimeApprovers::class);
    OvertimeRequest::query()->get()->each(fn (OvertimeRequest $r) => expect(
        app(PendingApprovals::class)->decidableBy($this->lead)->whereKey($r->id)->exists()
    )->toBe($approvers->canApprove($this->lead, $r)));
});

test('Project Managers and Project Directors see everyone\'s pending overtime except their own', function () {
    $requests = collect([$this->member, $this->outsider, $this->lead, $this->otherLead, $this->manager, $this->director])
        ->mapWithKeys(fn (User $u) => [$u->id => approvalsRequestFor($u)->id]);

    expect(approvalsPendingIds($this, $this->manager))->toBe($requests->except($this->manager->id)->sort()->values()->all())
        ->and(approvalsPendingIds($this, $this->director))->toBe($requests->except($this->director->id)->sort()->values()->all());
});

test('the inbox lists pending requests oldest first with the details an approver needs', function () {
    $newer = approvalsRequestFor($this->member, ['started_at' => '2026-09-14 10:00:00', 'reason' => 'Render shot 40', 'is_late_claim' => true]);
    $older = approvalsRequestFor($this->memberTwo, ['started_at' => '2026-09-11 10:00:00'], ['work_date' => '2026-09-11', 'flags' => ['clock_mismatch', 'gap_unverified']]);

    $this->actingAs($this->lead)->get(route('approvals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('approvals/Index')
            ->where('abilities', ['approve' => true, 'change' => false])
            ->where('pending.0.id', $older->id)
            ->where('pending.0.work_date', '2026-09-11')
            ->where('pending.0.flags', ['clock_mismatch', 'gap_unverified'])
            ->where('pending.0.teams', ['Animation'])
            ->where('pending.1.id', $newer->id)
            ->where('pending.1.person.name', $this->member->name)
            ->where('pending.1.person.initials', $this->member->initials())
            ->where('pending.1.reason', 'Render shot 40')
            ->where('pending.1.work_report', 'Render shot 12 selesai')
            ->where('pending.1.minutes', 120)
            ->where('pending.1.flags', ['late_claim'])
            ->where('pending.1.blocked', null)
            ->where('teams', [['id' => $this->team->id, 'name' => 'Animation']])
            ->where('bulk_result', null));
});

test('a request on a shift closed for review is flagged needs review', function () {
    approvalsRequestFor($this->member, [], ['status' => ShiftStatus::NeedsReview]);

    $this->actingAs($this->lead)->get(route('approvals.index'))
        ->assertInertia(fn ($page) => $page->where('pending.0.flags', ['needs_review']));
});

test('people without an approve or change permission cannot open the inbox', function () {
    $this->actingAs($this->member)->get(route('approvals.index'))->assertForbidden();
});

test('approving from the page records the decision through DecideOvertime', function () {
    $request = approvalsRequestFor($this->member);

    approvalsDecide($this, $this->lead, $request)
        ->assertRedirect(route('approvals.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', "Lembur {$this->member->name} disetujui.");

    expect($request->refresh()->status)->toBe(OvertimeStatus::Approved)
        ->and($request->decisions()->sole()->decided_by)->toBe($this->lead->id)
        ->and(AuditLog::query()->where('action', 'overtime.decided')->count())->toBe(1);
});

test('3.4.5 rejecting needs a note; approving takes an optional one', function () {
    $request = approvalsRequestFor($this->member);

    approvalsDecide($this, $this->lead, $request, ['decision' => 'rejected', 'note' => '   '])->assertSessionHasErrors('note');
    expect($request->refresh()->status)->toBe(OvertimeStatus::Pending);

    approvalsDecide($this, $this->lead, $request, ['decision' => 'rejected', 'note' => 'Render bisa besok pagi'])->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe(OvertimeStatus::Rejected)
        ->and($request->decisions()->sole()->note)->toBe('Render bisa besok pagi');

    $second = approvalsRequestFor($this->memberTwo);
    approvalsDecide($this, $this->lead, $second, ['note' => 'Terima kasih sudah lembur'])->assertSessionHasNoErrors();
    expect($second->decisions()->sole()->note)->toBe('Terima kasih sudah lembur');
});

test('3.4.5 a request still running or waiting for its report is shown with the reason and refused', function () {
    $desk = Desk::for($this, $this->member);
    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:03');
    $request = OvertimeRequest::query()->sole();

    $this->actingAs($this->lead)->get(route('approvals.index'))
        ->assertInertia(fn ($page) => $page->where('pending.0.blocked', 'running'));
    approvalsDecide($this, $this->lead, $request)->assertSessionHasErrors('overtime');

    $desk->send('clock_out', at: '19:00');

    $this->actingAs($this->lead)->get(route('approvals.index'))
        ->assertInertia(fn ($page) => $page->where('pending.0.blocked', 'report_due'));
    approvalsDecide($this, $this->lead, $request)->assertSessionHasErrors('overtime');

    $desk->send('overtime_report', ['work_report' => 'Render selesai'], '19:01');
    approvalsDecide($this, $this->lead, $request)->assertSessionHasNoErrors();

    expect($request->refresh()->status)->toBe(OvertimeStatus::Approved);
});

test('overtime that ended with 0 minutes is not listed and not counted in the badge (I16)', function () {
    $desk = Desk::for($this, $this->member);
    $desk->send('clock_in', at: '2026-09-14 09:00');
    $desk->send('overtime_start', ['reason' => 'Render final shot 12'], '17:00:10');
    $desk->send('clock_out', ['work_report' => 'Render ternyata sudah selesai'], '17:00:40');

    expect(approvalsPendingIds($this, $this->lead))->toBe([])
        ->and(app(PendingApprovals::class)->countFor($this->lead))->toBe(0);
});

test('a decision outside the approver\'s scope comes back as a form error, not a crash', function () {
    $request = approvalsRequestFor($this->outsider);

    approvalsDecide($this, $this->lead, $request)->assertRedirect(route('approvals.index'))->assertSessionHasErrors('overtime');
    expect($request->refresh()->status)->toBe(OvertimeStatus::Pending);
});

test('a decision is not sent when someone else decided the request after the page loaded', function () {
    $request = approvalsRequestFor($this->member);
    app(DecideOvertime::class)($this->manager, $request, Decision::Approved);

    $this->actingAs($this->director)->from(route('approvals.index'))->post(route('approvals.decide', $request), [
        'decision' => 'rejected',
        'note' => 'Tidak ada permintaan klien',
        'seen_status' => 'pending',
        'seen_minutes' => 120,
        'seen_decision_id' => null,
    ])->assertSessionHasErrors('overtime');

    expect(session('errors')->first('overtime'))
        ->toStartWith("Lembur ini sudah diputuskan oleh {$this->manager->name} pada ")
        ->toEndWith('(disetujui). Keputusanmu tidak dikirim.')
        ->and($request->refresh()->status)->toBe(OvertimeStatus::Approved)
        ->and($request->decisions()->count())->toBe(1);
});

test('a decision is not sent when the minutes changed after the page loaded', function () {
    $request = approvalsRequestFor($this->member);

    approvalsDecide($this, $this->lead, $request, ['seen_minutes' => 60])
        ->assertSessionHasErrors(['overtime' => 'Menit lembur ini berubah dari 1 j jadi 2 j. Periksa lagi sebelum memutuskan. Keputusanmu tidak dikirim.']);

    expect($request->refresh()->status)->toBe(OvertimeStatus::Pending);
});

test('3.4.7 changing a decision needs the change permission and a note', function () {
    $request = approvalsRequestFor($this->member);
    approvalsDecide($this, $this->lead, $request, ['decision' => 'rejected', 'note' => 'Tidak ada permintaan klien']);

    approvalsDecide($this, $this->lead, $request, ['note' => 'Ternyata diminta klien'])->assertSessionHasErrors('overtime');
    approvalsDecide($this, $this->manager, $request, ['note' => 'Ternyata diminta klien'])->assertSessionHasErrors('overtime');
    approvalsDecide($this, $this->director, $request)->assertSessionHasErrors('note');
    expect($request->refresh()->status)->toBe(OvertimeStatus::Rejected);

    approvalsDecide($this, $this->director, $request, ['note' => 'Ternyata diminta klien'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', "Keputusan lembur {$this->member->name} diubah jadi disetujui.");
    approvalsDecide($this, $this->admin, $request, ['decision' => 'rejected', 'note' => 'Dikoreksi HR'])->assertSessionHasNoErrors();

    expect($request->refresh()->status)->toBe(OvertimeStatus::Rejected)
        ->and(AuditLog::query()->where('subject_id', $request->id)->pluck('action')->all())
        ->toBe(['overtime.decided', 'overtime.decision_changed', 'overtime.decision_changed']);
});

test('I12 Superadmin sees decisions to change but makes no first decision', function () {
    $pending = approvalsRequestFor($this->member);
    $decided = approvalsRequestFor($this->outsider);
    app(DecideOvertime::class)($this->otherLead, $decided, Decision::Approved, 'Oke');

    $this->actingAs($this->admin)->get(route('approvals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('abilities', ['approve' => false, 'change' => true])
            ->has('pending', 0)
            ->has('decided', 1)
            ->where('decided.0.id', $decided->id)
            ->where('decided.0.decision.decision', 'approved')
            ->where('decided.0.decision.decided_by', $this->otherLead->name)
            ->where('decided.0.decision.note', 'Oke')
            ->where('decided.0.can_change', true));

    approvalsDecide($this, $this->admin, $pending)->assertSessionHasErrors('overtime');
    expect($pending->refresh()->status)->toBe(OvertimeStatus::Pending);

    $this->actingAs($this->admin)->post(route('approvals.bulk'), ['items' => [['id' => $pending->id, 'seen_minutes' => 120]]])->assertForbidden();
});

test('recent decisions follow the viewer\'s scope and only change-permission holders may change them', function () {
    $inTeam = approvalsRequestFor($this->member);
    $outside = approvalsRequestFor($this->outsider);
    $ownOfDirector = approvalsRequestFor($this->director);
    app(DecideOvertime::class)($this->manager, $inTeam, Decision::Approved);
    app(DecideOvertime::class)($this->manager, $outside, Decision::Rejected, 'Bukan prioritas');
    app(DecideOvertime::class)($this->manager, $ownOfDirector, Decision::Approved);

    $this->actingAs($this->lead)->get(route('approvals.index'))
        ->assertInertia(fn ($page) => $page->has('decided', 1)->where('decided.0.id', $inTeam->id)->where('decided.0.can_change', false));

    $this->actingAs($this->director)->get(route('approvals.index'))
        ->assertInertia(fn ($page) => $page
            ->where('abilities.change', true)
            ->has('decided', 2)
            ->where('decided.0.id', $outside->id)
            ->where('decided.0.can_change', true));
});

test('a request reset to pending shows the decision it had before', function () {
    $request = approvalsRequestFor($this->member);
    $decision = app(DecideOvertime::class)($this->lead, $request, Decision::Approved);
    $request->update(['status' => OvertimeStatus::Pending, 'minutes' => 180]);

    $this->actingAs($this->lead)->get(route('approvals.index'))
        ->assertInertia(fn ($page) => $page->where('pending.0.decision.id', $decision->id)->where('pending.0.minutes', 180));

    approvalsDecide($this, $this->lead, $request)->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe(OvertimeStatus::Approved);
});

test('bulk approve approves clean requests and counts every skipped one by reason', function () {
    $clean = approvalsRequestFor($this->member);
    $cleanToo = approvalsRequestFor($this->memberTwo);
    $late = approvalsRequestFor($this->member, ['is_late_claim' => true]);
    $review = approvalsRequestFor($this->member, [], ['status' => ShiftStatus::NeedsReview]);
    $mismatch = approvalsRequestFor($this->member, [], ['flags' => ['clock_mismatch']]);
    $gap = approvalsRequestFor($this->memberTwo, [], ['flags' => ['gap_unverified']]);
    $reportDue = approvalsRequestFor($this->memberTwo, ['work_report' => null, 'submitted_at' => null]);
    $running = approvalsRequestFor($this->memberTwo, ['ended_at' => null, 'work_report' => null]);
    $changed = approvalsRequestFor($this->member);
    $decided = approvalsRequestFor($this->memberTwo);
    app(DecideOvertime::class)($this->manager, $decided, Decision::Approved);
    $outside = approvalsRequestFor($this->outsider);

    $items = collect([$clean, $cleanToo, $late, $review, $mismatch, $gap, $reportDue, $running, $decided, $outside])
        ->map(fn (OvertimeRequest $r) => ['id' => $r->id, 'seen_minutes' => $r->minutes])
        ->push(['id' => $changed->id, 'seen_minutes' => 45])
        ->all();

    $this->actingAs($this->lead)->from(route('approvals.index'))->post(route('approvals.bulk'), ['items' => $items])
        ->assertRedirect(route('approvals.index'))
        ->assertSessionHas('approvals_bulk', [
            'approved' => 2,
            'skipped' => ['flagged' => 4, 'not_ready' => 2, 'changed' => 2, 'not_allowed' => 1],
        ]);

    expect($clean->refresh()->status)->toBe(OvertimeStatus::Approved)
        ->and($cleanToo->refresh()->status)->toBe(OvertimeStatus::Approved)
        ->and(OvertimeRequest::query()->where('status', OvertimeStatus::Approved)->count())->toBe(3)
        ->and(OvertimeRequest::query()->whereKey([$late->id, $review->id, $mismatch->id, $gap->id, $reportDue->id, $running->id, $changed->id, $outside->id])
            ->where('status', OvertimeStatus::Pending)->count())->toBe(8);

    $this->actingAs($this->lead)->get(route('approvals.index'))
        ->assertInertia(fn ($page) => $page->where('bulk_result.approved', 2)->where('bulk_result.skipped.flagged', 4));
});

test('bulk approve needs at least one selected request', function () {
    $this->actingAs($this->lead)->from(route('approvals.index'))->post(route('approvals.bulk'), ['items' => []])
        ->assertSessionHasErrors('items');
});

test('Q13 the nav badge counts pending overtime the viewer can decide now', function () {
    approvalsRequestFor($this->member);
    approvalsRequestFor($this->memberTwo);
    approvalsRequestFor($this->memberTwo, ['ended_at' => null, 'work_report' => null]);
    approvalsRequestFor($this->memberTwo, ['work_report' => null]);
    approvalsRequestFor($this->outsider);
    approvalsRequestFor($this->lead);
    $decided = approvalsRequestFor($this->member);
    app(DecideOvertime::class)($this->manager, $decided, Decision::Approved);

    $badge = fn (User $user, array $expected) => $this->actingAs($user)->get(route('my-day'))
        ->assertInertia(fn ($page) => $page->where('nav_badges', $expected));

    $badge($this->lead, ['approvals' => 2]);
    $badge($this->manager, ['approvals' => 4]);
    $badge($this->member, []);
    $badge($this->admin, []);

    expect(app(PendingApprovals::class)->countFor($this->director))->toBe(4);
});

test('the Persetujuan menu item appears for approvers once the route exists', function () {
    $this->actingAs($this->lead)->get(route('my-day'))
        ->assertInertia(fn ($page) => $page->where('nav.1.items', fn ($items) => collect($items)->contains('key', 'approvals')));
});
