<?php

use App\Modules\Calendar\Enums\CalendarDayType;
use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Identity\Access\Role;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveQuota;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Organization\Models\Team;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\Leave\Support\LeaveFixtures;

// Monday 2026-09-14, 10:00 Asia/Jakarta. 2026-09-19 is a Saturday.

beforeEach(function () {
    $this->travelTo(Desk::time('2026-09-14 10:00'));
    $this->person = userWithRole(Role::Employee);
    $this->admin = userWithRole(Role::Superadmin);
    $this->annual = LeaveFixtures::type('annual');
    $this->sick = LeaveFixtures::type('sick');
});

function leaveSend(object $test, $person, array $data)
{
    return $test->actingAs($person)->from(route('leave.mine'))->post(route('leave.store'), $data);
}

it('seeds the five default leave types, only annual leave counting against the quota', function () {
    expect(LeaveType::query()->ordered()->get(['code', 'counts_against_quota'])->map(fn ($t) => [$t->code, $t->counts_against_quota])->all())
        ->toBe([['annual', true], ['sick', false], ['permission', false], ['special', false], ['unpaid', false]]);
});

it('counts only workdays: weekends and holidays are skipped', function () {
    CalendarDay::query()->create(['date' => '2026-09-17', 'type' => CalendarDayType::Holiday, 'name' => 'Libur', 'created_by' => $this->admin->id]);

    leaveSend($this, $this->person, ['leave_type_id' => $this->annual->id, 'start_date' => '2026-09-16', 'end_date' => '2026-09-22'])
        ->assertRedirect(route('leave.mine'))
        ->assertSessionHasNoErrors();

    $leave = LeaveRequest::query()->sole();

    // Wed 16, Fri 18, Mon 21, Tue 22
    expect($leave->days)->toBe(4)
        ->and($leave->status)->toBe(LeaveStatus::Pending)
        ->and(AuditLog::query()->where('action', 'leave.requested')->sole()->after)
        ->toMatchArray(['type' => 'annual', 'start_date' => '2026-09-16', 'end_date' => '2026-09-22', 'days' => 4]);
});

it('counts a Saturday the studio opened for the person', function () {
    OpenedWorkday::query()->create(['date' => '2026-09-19', 'scope_type' => OpenedScope::User, 'scope_id' => $this->person->id, 'opened_by' => $this->admin->id]);

    leaveSend($this, $this->person, ['leave_type_id' => $this->annual->id, 'start_date' => '2026-09-19', 'end_date' => '2026-09-20'])
        ->assertSessionHasNoErrors();

    expect(LeaveRequest::query()->sole()->days)->toBe(1);
});

it('refuses a range without workdays', function () {
    leaveSend($this, $this->person, ['leave_type_id' => $this->annual->id, 'start_date' => '2026-09-19', 'end_date' => '2026-09-20'])
        ->assertSessionHasErrors('end_date');

    expect(LeaveRequest::query()->count())->toBe(0);
});

it('refuses dates that overlap a pending or approved request, and allows them after a rejection', function (LeaveStatus $status, bool $refused) {
    LeaveFixtures::request($this->person, '2026-09-15', '2026-09-16', $status);

    $response = leaveSend($this, $this->person, ['leave_type_id' => $this->sick->id, 'start_date' => '2026-09-16', 'end_date' => '2026-09-18', 'reason' => 'Demam']);

    $refused ? $response->assertSessionHasErrors('start_date') : $response->assertSessionHasNoErrors();
})->with([
    'pending' => [LeaveStatus::Pending, true],
    'approved' => [LeaveStatus::Approved, true],
    'rejected' => [LeaveStatus::Rejected, false],
    'cancelled' => [LeaveStatus::Cancelled, false],
]);

it('accepts annual leave beyond the quota: the quota is a record Superadmin reads', function () {
    LeaveQuota::query()->create(['user_id' => $this->person->id, 'year' => 2026, 'days' => 5]);
    LeaveFixtures::request($this->person, '2026-09-21', '2026-09-22', LeaveStatus::Approved);
    LeaveFixtures::request($this->person, '2026-09-23', '2026-09-24', LeaveStatus::Pending);

    leaveSend($this, $this->person, ['leave_type_id' => $this->annual->id, 'start_date' => '2026-09-28', 'end_date' => '2026-09-29'])
        ->assertSessionHasNoErrors();

    $sent = LeaveRequest::query()->where('user_id', $this->person->id)->where('start_date', '2026-09-28')->sole();

    // Superadmin sees the person 1 day over, on the quota tab and on the pending request they decide
    $this->actingAs($this->admin)->get(route('admin.leave.index'))
        ->assertInertia(fn ($page) => $page
            ->where('quota.rows', fn ($rows) => collect($rows)->firstWhere('id', $this->person->id)['remaining'] === -1)
            ->where('requests.data', fn ($items) => collect($items)->firstWhere('id', $sent->id)['balance']['remaining'] === -1));
});

it('does not limit types that do not count against the quota', function () {
    LeaveQuota::query()->create(['user_id' => $this->person->id, 'year' => 2026, 'days' => 0]);

    leaveSend($this, $this->person, ['leave_type_id' => $this->sick->id, 'start_date' => '2026-09-21', 'end_date' => '2026-10-02', 'reason' => 'Rawat inap'])
        ->assertSessionHasNoErrors();

    expect(LeaveRequest::query()->sole()->days)->toBe(10);
});

it('uses the quota setting when the person has no quota row', function () {
    // Default 12 days: 13 workdays from Monday 21 September to Wednesday 7 October
    leaveSend($this, $this->person, ['leave_type_id' => $this->annual->id, 'start_date' => '2026-09-21', 'end_date' => '2026-10-07'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get(route('admin.leave.index'))
        ->assertInertia(fn ($page) => $page->where('quota.rows', fn ($rows) => collect($rows)->firstWhere('id', $this->person->id)['quota'] === 12
            && collect($rows)->firstWhere('id', $this->person->id)['remaining'] === -1));
});

it('asks for a reason when the type requires one', function () {
    leaveSend($this, $this->person, ['leave_type_id' => $this->sick->id, 'start_date' => '2026-09-15', 'end_date' => '2026-09-15', 'reason' => '  '])
        ->assertSessionHasErrors('reason');
});

it('refuses a switched-off type, a range across years, and a start too far back', function (array $data, string $field) {
    $this->annual->update(['is_active' => ! ($data['inactive'] ?? false)]);
    unset($data['inactive']);

    leaveSend($this, $this->person, ['leave_type_id' => $this->annual->id, ...$data])->assertSessionHasErrors($field);
})->with([
    'inactive type' => [['start_date' => '2026-09-15', 'end_date' => '2026-09-15', 'inactive' => true], 'leave_type_id'],
    'across years' => [['start_date' => '2026-12-30', 'end_date' => '2027-01-04'], 'end_date'],
    'end before start' => [['start_date' => '2026-09-16', 'end_date' => '2026-09-15'], 'end_date'],
    'too far back' => [['start_date' => '2026-08-01', 'end_date' => '2026-08-03'], 'start_date'],
]);

it('keeps the attachment on the private disk and serves it to the owner, deciders, and Superadmin only', function () {
    Storage::fake('local');
    $lead = userWithRole(Role::TeamLead);
    Team::factory()->create(['lead_user_id' => $lead->id])->members()->attach($this->person);
    $otherLead = userWithRole(Role::TeamLead);
    Team::factory()->create(['lead_user_id' => $otherLead->id]);
    $colleague = userWithRole(Role::Employee);
    $manager = userWithRole(Role::ProjectManager);

    leaveSend($this, $this->person, [
        'leave_type_id' => $this->sick->id,
        'start_date' => '2026-09-14',
        'end_date' => '2026-09-15',
        'reason' => 'Demam',
        'attachment' => UploadedFile::fake()->create('surat dokter.pdf', 200, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $leave = LeaveRequest::query()->sole();
    Storage::disk('local')->assertExists($leave->attachment_path);

    expect($leave->attachment_name)->toBe('surat dokter.pdf');

    foreach ([$this->person, $lead, $manager, $this->admin] as $allowed) {
        $this->actingAs($allowed)->get(route('leave.attachment', $leave))->assertOk()->assertDownload('surat dokter.pdf');
    }

    foreach ([$colleague, $otherLead] as $refused) {
        $this->actingAs($refused)->get(route('leave.attachment', $leave))->assertForbidden();
    }
});

it('refuses an attachment that is not a PDF or an image', function () {
    Storage::fake('local');

    leaveSend($this, $this->person, [
        'leave_type_id' => $this->annual->id,
        'start_date' => '2026-09-15',
        'end_date' => '2026-09-15',
        'attachment' => UploadedFile::fake()->create('script.zip', 10, 'application/zip'),
    ])->assertSessionHasErrors('attachment');

    expect(LeaveRequest::query()->count())->toBe(0);
});

it('previews the workdays a range would count', function () {
    CalendarDay::query()->create(['date' => '2026-09-17', 'type' => CalendarDayType::Holiday, 'name' => 'Libur', 'created_by' => $this->admin->id]);

    $this->actingAs($this->person)
        ->getJson(route('leave.preview', ['start_date' => '2026-09-16', 'end_date' => '2026-09-22', 'leave_type_id' => $this->annual->id]))
        ->assertOk()
        ->assertJson(['days' => 4, 'error' => null])
        ->assertJsonPath('remaining', null);

    // Only Superadmin receives what is left of the quota
    $this->actingAs($this->admin)
        ->getJson(route('leave.preview', ['start_date' => '2026-09-16', 'end_date' => '2026-09-22', 'leave_type_id' => $this->annual->id]))
        ->assertJsonPath('remaining', 12);

    $this->actingAs($this->person)
        ->getJson(route('leave.preview', ['start_date' => '2026-09-19', 'end_date' => '2026-09-20']))
        ->assertOk()
        ->assertJson(['days' => 0]);
});

it('shows the active types and only the person\'s own requests, and the balance to Superadmin only', function () {
    $colleague = userWithRole(Role::Employee);
    LeaveFixtures::request($this->person, '2026-09-21', '2026-09-22', LeaveStatus::Approved);
    LeaveFixtures::request($this->person, '2026-09-24', '2026-09-24', LeaveStatus::Pending);
    LeaveFixtures::request($colleague, '2026-09-24', '2026-09-24', LeaveStatus::Pending);
    LeaveFixtures::type('unpaid')->update(['is_active' => false]);

    $this->actingAs($this->person)->get(route('leave.mine'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('leave/Index')
            ->where('balance', null)
            ->has('types', 4)
            ->has('requests', 2)
            ->where('requests.0.start_date', '2026-09-24')
            ->where('requests.0.can_cancel', true));

    LeaveFixtures::request($this->admin, '2026-09-21', '2026-09-22', LeaveStatus::Approved);

    $this->actingAs($this->admin)->get(route('leave.mine'))
        ->assertInertia(fn ($page) => $page->where('balance', ['year' => 2026, 'quota' => 12, 'custom' => false, 'used' => 2, 'pending' => 0, 'remaining' => 10]));
});
