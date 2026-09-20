<?php

use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Organization\Models\Team;
use App\Modules\Overtime\Actions\DecideOvertime;
use App\Modules\Overtime\Enums\Decision;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Overtime\Services\OvertimeApprovers;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->request = OvertimeRequest::factory()->create([
        'shift_id' => Shift::factory()->create(['user_id' => $this->person->id])->id,
    ]);
    $this->decide = app(DecideOvertime::class);
});

it('refuses a Project Manager deciding their own request', function () {
    $manager = userWithRole(Role::ProjectManager);
    $own = OvertimeRequest::factory()->create(['shift_id' => Shift::factory()->create(['user_id' => $manager->id])->id]);

    expect(fn () => ($this->decide)($manager, $own, Decision::Approved))->toThrow(AuthorizationException::class)
        ->and($own->refresh()->status)->toBe(OvertimeStatus::Pending);
});

it('refuses an employee without the approve permission', function () {
    $colleague = userWithRole(Role::Employee);

    expect(fn () => ($this->decide)($colleague, $this->request, Decision::Approved))->toThrow(AuthorizationException::class);
});

it('refuses a Team Lead of a team the person is not in', function () {
    $lead = userWithRole(Role::TeamLead);
    Team::factory()->create(['lead_user_id' => $lead->id]);

    expect(fn () => ($this->decide)($lead, $this->request, Decision::Approved))->toThrow(AuthorizationException::class);
});

it('lets the Team Lead of any of the person\'s teams decide', function () {
    $lead = userWithRole(Role::TeamLead);
    Team::factory()->create(['lead_user_id' => $lead->id])->members()->attach($this->person);
    Team::factory()->create()->members()->attach($this->person);

    $decision = ($this->decide)($lead, $this->request, Decision::Approved, '  ');

    expect($decision->note)->toBeNull()
        ->and($decision->decided_by)->toBe($lead->id)
        ->and($this->request->refresh()->status)->toBe(OvertimeStatus::Approved)
        ->and(AuditLog::query()->where('action', 'overtime.decided')->sole()->after)->toEqual(['status' => 'approved', 'note' => null]);
});

it('leaves suspended managers out of the approver list', function () {
    $director = userWithRole(Role::ProjectDirector);
    userWithRole(Role::ProjectDirector)->forceFill(['status' => UserStatus::Suspended])->save();

    expect(app(OvertimeApprovers::class)->for($this->request)->pluck('id')->all())->toBe([$director->id]);
});
