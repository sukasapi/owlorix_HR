<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Services\LeaveBalance;
use App\Modules\Shared\Audit\AuditLog;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\Leave\Support\LeaveFixtures;

beforeEach(function () {
    $this->travelTo(Desk::time('2026-09-14 10:00'));
    $this->person = userWithRole(Role::Employee);
    $this->admin = userWithRole(Role::Superadmin);
});

function leaveCancel(object $test, $actor, $leave, ?string $note = null)
{
    return $test->actingAs($actor)->from(route('leave.mine'))->post(route('leave.cancel', $leave), ['note' => $note]);
}

it('lets the owner cancel a pending request any time, even after it started', function () {
    $leave = LeaveFixtures::request($this->person, '2026-09-10', '2026-09-15');

    leaveCancel($this, $this->person, $leave)->assertSessionHasNoErrors();

    expect($leave->refresh())
        ->status->toBe(LeaveStatus::Cancelled)
        ->cancelled_by->toBe($this->person->id)
        ->and(AuditLog::query()->where('action', 'leave.cancelled')->sole())
        ->before->toEqual(['status' => 'pending'])
        ->after->toEqual(['status' => 'cancelled', 'note' => null]);
});

it('lets the owner cancel approved leave until the start date has passed', function (string $start, bool $allowed) {
    $leave = LeaveFixtures::request($this->person, $start, '2026-09-18', LeaveStatus::Approved);

    $response = leaveCancel($this, $this->person, $leave);

    $allowed ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('leave');
    expect($leave->refresh()->status)->toBe($allowed ? LeaveStatus::Cancelled : LeaveStatus::Approved);
})->with([
    'starts tomorrow' => ['2026-09-15', true],
    'starts today' => ['2026-09-14', true],
    'started yesterday' => ['2026-09-11', false],
]);

it('lets Superadmin cancel approved leave any time, with a note', function () {
    $leave = LeaveFixtures::request($this->person, '2026-09-10', '2026-09-18', LeaveStatus::Approved);

    leaveCancel($this, $this->admin, $leave)->assertSessionHasErrors('note');
    leaveCancel($this, $this->admin, $leave, 'Dipanggil kembali untuk rilis.')->assertSessionHasNoErrors();

    expect($leave->refresh())
        ->status->toBe(LeaveStatus::Cancelled)
        ->cancel_note->toBe('Dipanggil kembali untuk rilis.')
        ->cancelled_by->toBe($this->admin->id);
});

it('refuses other people and closed requests', function () {
    $leave = LeaveFixtures::request($this->person, '2026-09-21', '2026-09-21');
    $manager = userWithRole(Role::ProjectManager);

    leaveCancel($this, userWithRole(Role::Employee), $leave)->assertForbidden();
    leaveCancel($this, $manager, $leave)->assertForbidden();

    $leave->update(['status' => LeaveStatus::Rejected]);

    leaveCancel($this, $this->person, $leave)->assertSessionHasErrors('leave');
});

it('gives the days back to the quota after a cancellation', function () {
    $leave = LeaveFixtures::request($this->person, '2026-09-21', '2026-09-25', LeaveStatus::Approved);

    leaveCancel($this, $this->person, $leave);

    expect(app(LeaveBalance::class)->for($this->person->id, 2026))->toMatchArray(['used' => 0, 'remaining' => 12]);
});
