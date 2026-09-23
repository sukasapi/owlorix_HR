<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveQuota;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Shared\Audit\AuditLog;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\Leave\Support\LeaveFixtures;

beforeEach(function () {
    $this->travelTo(Desk::time('2026-09-14 10:00'));
    $this->admin = userWithRole(Role::Superadmin);
    $this->person = userWithRole(Role::Employee);
});

it('opens only for people who manage leave', function (Role $role) {
    $this->actingAs(userWithRole($role))->get(route('admin.leave.index'))->assertForbidden();
})->with([Role::Employee, Role::TeamLead, Role::ProjectManager, Role::ProjectDirector]);

it('lists every request with pending first and filters by status, person, and month', function () {
    $other = userWithRole(Role::Employee);
    $approved = LeaveFixtures::request($this->person, '2026-09-21', '2026-09-21', LeaveStatus::Approved);
    $pending = LeaveFixtures::request($other, '2026-10-05', '2026-10-06');

    $this->actingAs($this->admin)->get(route('admin.leave.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/leave/Index')
            ->where('pending_count', 1)
            ->where('requests.total', 2)
            ->where('requests.data.0.id', $pending->id)
            ->where('requests.data.0.can_decide', true));

    $this->actingAs($this->admin)->get(route('admin.leave.index', ['status' => 'approved']))
        ->assertInertia(fn ($page) => $page->where('requests.total', 1)->where('requests.data.0.id', $approved->id));

    $this->actingAs($this->admin)->get(route('admin.leave.index', ['orang' => (string) $other->id]))
        ->assertInertia(fn ($page) => $page->where('requests.total', 1)->where('filters.orang', $other->id));

    $this->actingAs($this->admin)->get(route('admin.leave.index', ['bulan' => '2026-10']))
        ->assertInertia(fn ($page) => $page->where('requests.total', 1)->where('requests.data.0.id', $pending->id));
});

it('changes a quota for a year and records the change', function () {
    LeaveFixtures::request($this->person, '2026-09-21', '2026-09-22', LeaveStatus::Approved);

    $this->actingAs($this->admin)->from(route('admin.leave.index'))
        ->put(route('admin.leave.quota', $this->person), ['year' => 2026, 'days' => 8])
        ->assertSessionHasNoErrors();

    expect(LeaveQuota::query()->sole())->days->toBe(8)->year->toBe(2026)
        ->and(AuditLog::query()->where('action', 'leave.quota_changed')->sole())
        ->subject_id->toBe($this->person->id)
        ->before->toEqual(['year' => 2026, 'days' => 12, 'custom' => false])
        ->after->toEqual(['year' => 2026, 'days' => 8]);

    $this->actingAs($this->admin)->get(route('admin.leave.index'))
        ->assertInertia(fn ($page) => $page->where('quota.rows', fn ($rows) => collect($rows)->firstWhere('id', $this->person->id)['remaining'] === 6));

    $this->actingAs($this->admin)->put(route('admin.leave.quota', $this->person), ['year' => 2026, 'days' => 400])->assertSessionHasErrors('days');
});

it('adds a leave type and edits it, both audited', function () {
    $this->actingAs($this->admin)->from(route('admin.leave.index'))
        ->post(route('admin.leave.types.store'), ['name' => 'Cuti haid', 'counts_against_quota' => false, 'requires_note' => false, 'is_active' => true])
        ->assertSessionHasNoErrors();

    $type = LeaveType::query()->where('name', 'Cuti haid')->sole();

    expect($type->code)->toBe('cuti_haid')
        ->and(AuditLog::query()->where('action', 'leave.type_created')->sole()->after)->toMatchArray(['code' => 'cuti_haid', 'name' => 'Cuti haid']);

    $this->actingAs($this->admin)->put(route('admin.leave.types.update', $type), ['name' => 'Cuti haid', 'counts_against_quota' => false, 'requires_note' => true, 'is_active' => false])
        ->assertSessionHasNoErrors();

    expect($type->refresh())->requires_note->toBeTrue()->is_active->toBeFalse()
        ->and(AuditLog::query()->where('action', 'leave.type_updated')->sole())
        ->before->toEqual(['requires_note' => false, 'is_active' => true])
        ->after->toEqual(['requires_note' => true, 'is_active' => false]);

    $this->actingAs($this->admin)->post(route('admin.leave.types.store'), ['name' => 'sakit', 'counts_against_quota' => false, 'requires_note' => false, 'is_active' => true])
        ->assertSessionHasErrors('name');
});

it('lets Superadmin decide and cancel any request from the admin page routes', function () {
    $leave = LeaveFixtures::request($this->person, '2026-09-21', '2026-09-21');

    $this->actingAs($this->admin)->post(route('leave.decide', $leave), ['decision' => 'approved'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('leave.cancel', $leave), ['note' => 'Salah tanggal, sudah dibicarakan.'])->assertSessionHasNoErrors();

    expect($leave->refresh()->status)->toBe(LeaveStatus::Cancelled);
});
