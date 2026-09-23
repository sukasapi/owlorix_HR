<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Leave\Actions\DecideLeave;
use App\Modules\Leave\Enums\LeaveDecision;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Organization\Models\Team;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\Leave\Support\LeaveFixtures;

beforeEach(function () {
    $this->travelTo(Desk::time('2026-09-14 10:00'));
    $this->person = userWithRole(Role::Employee);
    $this->lead = userWithRole(Role::TeamLead);
    Team::factory()->create(['name' => 'Animation', 'lead_user_id' => $this->lead->id])->members()->attach([$this->person->id, $this->lead->id]);
    $this->leave = LeaveFixtures::request($this->person, '2026-09-21', '2026-09-22');
    $this->decide = app(DecideLeave::class);
});

function leaveDecide(object $test, $actor, $leave, string $decision, ?string $note = null)
{
    return $test->actingAs($actor)->from(route('leave.approvals'))->post(route('leave.decide', $leave), ['decision' => $decision, 'note' => $note]);
}

it('lets the Team Lead of the person\'s team approve, and records it', function () {
    leaveDecide($this, $this->lead, $this->leave, 'approved')->assertSessionHasNoErrors()->assertRedirect(route('leave.approvals'));

    $leave = $this->leave->refresh();

    expect($leave->status)->toBe(LeaveStatus::Approved)
        ->and($leave->decided_by)->toBe($this->lead->id)
        ->and($leave->decided_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'leave.approved')->sole())
        ->actor_id->toBe($this->lead->id)
        ->after->toEqual(['status' => 'approved', 'note' => null]);
});

it('refuses a Team Lead of another team', function () {
    $otherLead = userWithRole(Role::TeamLead);
    Team::factory()->create(['lead_user_id' => $otherLead->id]);

    leaveDecide($this, $otherLead, $this->leave, 'approved')->assertSessionHasErrors('leave');

    expect($this->leave->refresh()->status)->toBe(LeaveStatus::Pending);
});

it('lets Project Managers, Project Directors, and Superadmin decide anyone', function (Role $role) {
    $decider = userWithRole($role);

    leaveDecide($this, $decider, $this->leave, 'approved')->assertSessionHasNoErrors();

    expect($this->leave->refresh()->status)->toBe(LeaveStatus::Approved);
})->with([Role::ProjectManager, Role::ProjectDirector, Role::Superadmin]);

it('never lets anyone decide their own request', function (Role $role) {
    $self = userWithRole($role);
    $own = LeaveFixtures::request($self, '2026-09-28', '2026-09-28');

    expect(fn () => ($this->decide)($self, $own, LeaveDecision::Approved))->toThrow(AuthorizationException::class)
        ->and($own->refresh()->status)->toBe(LeaveStatus::Pending);
})->with([Role::ProjectManager, Role::ProjectDirector, Role::Superadmin]);

it('sends a Team Lead\'s own request to Project Managers and up, not to another lead of their team', function () {
    $coLead = userWithRole(Role::TeamLead);
    Team::factory()->create(['lead_user_id' => $coLead->id])->members()->attach($this->lead);
    $own = LeaveFixtures::request($this->lead, '2026-09-28', '2026-09-28');

    expect(fn () => ($this->decide)($coLead, $own, LeaveDecision::Approved))->toThrow(AuthorizationException::class);

    ($this->decide)(userWithRole(Role::ProjectManager), $own, LeaveDecision::Approved);

    expect($own->refresh()->status)->toBe(LeaveStatus::Approved);
});

it('refuses a suspended approver', function () {
    $manager = userWithRole(Role::ProjectManager);
    $manager->forceFill(['status' => UserStatus::Suspended])->save();

    expect(fn () => ($this->decide)($manager, $this->leave, LeaveDecision::Approved))->toThrow(AuthorizationException::class);
});

it('keeps employees out of the approval routes', function () {
    $colleague = userWithRole(Role::Employee);

    $this->actingAs($colleague)->get(route('leave.approvals'))->assertForbidden();
    $this->actingAs($colleague)->post(route('leave.decide', $this->leave), ['decision' => 'approved'])->assertForbidden();
});

it('needs a note to reject, and keeps it for the person', function () {
    leaveDecide($this, $this->lead, $this->leave, 'rejected', '  ')->assertSessionHasErrors('note');
    expect($this->leave->refresh()->status)->toBe(LeaveStatus::Pending);

    leaveDecide($this, $this->lead, $this->leave, 'rejected', 'Deadline klien minggu itu.')->assertSessionHasNoErrors();

    expect($this->leave->refresh())
        ->status->toBe(LeaveStatus::Rejected)
        ->decision_note->toBe('Deadline klien minggu itu.')
        ->and(AuditLog::query()->where('action', 'leave.rejected')->sole()->after)->toEqual(['status' => 'rejected', 'note' => 'Deadline klien minggu itu.']);
});

it('refuses to decide a request that is no longer pending', function () {
    $this->leave->update(['status' => LeaveStatus::Cancelled]);

    leaveDecide($this, $this->lead, $this->leave, 'approved')->assertSessionHasErrors('leave');

    expect($this->leave->refresh()->status)->toBe(LeaveStatus::Cancelled);
});

it('lists the requests a Team Lead decides, oldest first, with teammates off and without quota numbers', function () {
    $second = userWithRole(Role::Employee);
    Team::query()->where('name', 'Animation')->sole()->members()->attach($second);
    $this->travel(1)->minutes();
    $later = LeaveFixtures::request($second, '2026-09-22', '2026-09-23');
    $outsider = LeaveFixtures::request(userWithRole(Role::Employee), '2026-09-22', '2026-09-22');

    $this->actingAs($this->lead)->get(route('leave.approvals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('leave/Approvals')
            ->has('pending', 2)
            ->where('pending.0.id', $this->leave->id)
            ->where('pending.0.can_decide', true)
            // The quota is a record for Superadmin only
            ->missing('pending.0.balance')
            ->where('pending.0.also_off.0.name', $second->name)
            ->where('pending.1.id', $later->id));

    // Superadmin decides anyone, including people outside every team
    $this->actingAs(userWithRole(Role::Superadmin))->get(route('leave.approvals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('pending', 3)->where('pending.2.id', $outsider->id)->where('pending.0.balance.remaining', 10));
});

it('lists teammates off per request from their own team and dates only', function () {
    $animator = userWithRole(Role::Employee);
    $lighter = userWithRole(Role::Employee);
    $lighterMate = userWithRole(Role::Employee);
    Team::query()->where('name', 'Animation')->sole()->members()->attach($animator);
    Team::factory()->create(['name' => 'Lighting'])->members()->attach([$lighter->id, $lighterMate->id]);

    $this->travel(1)->minutes();
    $lighting = LeaveFixtures::request($lighter, '2026-09-21', '2026-09-21');
    // Overlaps the first request only, and is in Animation only
    LeaveFixtures::request($animator, '2026-09-22', '2026-09-22', LeaveStatus::Approved);
    // Overlaps the Lighting request, in Lighting only
    LeaveFixtures::request($lighterMate, '2026-09-21', '2026-09-21', LeaveStatus::Approved);
    // Rejected leave never shows
    LeaveFixtures::request($lighterMate, '2026-09-21', '2026-09-22', LeaveStatus::Rejected);
    // Another Animation request on dates nobody else is off
    $this->travel(1)->minutes();
    $alone = LeaveFixtures::request($animator, '2026-10-05', '2026-10-05');

    $pending = collect($this->actingAs(userWithRole(Role::Superadmin))->get(route('leave.approvals'))->assertOk()
        ->original->getData()['page']['props']['pending'])->keyBy('id');

    expect(array_column($pending[$this->leave->id]['also_off'], 'name'))->toBe([$animator->name])
        ->and($pending[$this->leave->id]['can_decide'])->toBeTrue()
        ->and(array_column($pending[$lighting->id]['also_off'], 'name'))->toBe([$lighterMate->name])
        ->and($pending[$alone->id]['also_off'])->toBe([]);
});

it('reads the approval inbox in the same number of queries however many requests wait', function () {
    $team = Team::query()->where('name', 'Animation')->sole();
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->lead)->get(route('leave.approvals'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $request = function (string $date) use ($team) {
        $person = userWithRole(Role::Employee);
        $team->members()->attach($person);
        LeaveFixtures::request($person, $date, $date);
        LeaveFixtures::request($person, '2026-09-21', '2026-09-21', LeaveStatus::Approved);
    };

    $request('2026-09-21');
    $queries();
    $few = $queries();

    foreach (['2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-28'] as $date) {
        $request($date);
    }

    expect($queries())->toBe($few)->toBeLessThan(40);
    $this->actingAs($this->lead)->get(route('leave.approvals'))
        ->assertInertia(fn ($page) => $page->has('pending', 7)->where('pending.6.can_decide', true));
});
