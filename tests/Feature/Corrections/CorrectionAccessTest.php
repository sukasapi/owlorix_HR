<?php

use App\Modules\Attendance\Models\Correction;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\Corrections\Support\Koreksi;

// Who may propose, apply and decline corrections, and for whom (3.10, scope as 3.4.4). Monday 2026-09-14, Asia/Jakarta.

beforeEach(function () {
    $this->admin = userWithRole(Role::Superadmin);
    $this->otherAdmin = userWithRole(Role::Superadmin);
    $this->lead = userWithRole(Role::TeamLead);
    $this->member = userWithRole(Role::Employee);
    $this->outsider = userWithRole(Role::Employee);
    $this->otherLead = userWithRole(Role::TeamLead);
    $this->manager = userWithRole(Role::ProjectManager);
    $this->director = userWithRole(Role::ProjectDirector);

    Team::factory()->create(['name' => 'Animation', 'lead_user_id' => $this->lead->id])
        ->members()->attach([$this->lead->id, $this->member->id, $this->otherLead->id]);
    Team::factory()->create(['name' => 'Lighting', 'lead_user_id' => $this->otherLead->id])
        ->members()->attach([$this->otherLead->id, $this->outsider->id]);
});

function correctionShiftOf(Tests\TestCase $test, User $person): App\Modules\Attendance\Models\Shift
{
    $shift = Koreksi::workedShift($test, $person, '2026-09-14 09:00', '15:00');
    $test->travelTo(Desk::time('2026-09-15 09:00'));

    return $shift;
}

it('sends a guest to sign in and refuses people without a correction permission', function () {
    $this->get(route('corrections.index'))->assertRedirect(route('sign-in'));

    $employee = userWithRole(Role::Employee);
    $shift = correctionShiftOf($this, $this->member);

    $this->actingAs($employee)->get(route('corrections.index'))->assertForbidden();
    Koreksi::submit($this, $employee, $shift, 'clock_out_at', '2026-09-14 17:00')->assertForbidden();
    Koreksi::preview($this, $employee, $shift, 'clock_out_at', '2026-09-14 17:00')->assertForbidden();

    expect(Correction::query()->count())->toBe(0);
});

it('lets Management propose only for their people, as for overtime approvals', function (string $actor, string $person, bool $allowed) {
    $shift = correctionShiftOf($this, $this->{$person});
    $response = Koreksi::submit($this, $this->{$actor}, $shift, 'clock_out_at', '2026-09-14 17:00');

    if ($allowed) {
        $response->assertSessionHasNoErrors();
        expect(Correction::query()->sole())->status->value->toBe('proposed')->proposed_by->toBe($this->{$actor}->id);
    } else {
        $response->assertSessionHasErrors(['correction' => 'Kamu tidak bisa mengajukan koreksi untuk orang ini.']);
        expect(Correction::query()->count())->toBe(0);
    }

    expect($shift->refresh()->regular_minutes)->toBe(360);
})->with([
    'Team Lead for a member of their team' => ['lead', 'member', true],
    'Team Lead for someone in another team' => ['lead', 'outsider', false],
    'Team Lead for a member who leads a team' => ['lead', 'otherLead', false],
    'Project Manager for anyone' => ['manager', 'outsider', true],
    'Project Director for a Team Lead' => ['director', 'otherLead', true],
]);

it('lets only Superadmin apply and decline', function () {
    $shift = correctionShiftOf($this, $this->member);
    Koreksi::submit($this, $this->lead, $shift, 'clock_out_at', '2026-09-14 17:00');
    $correction = Correction::query()->sole();

    Koreksi::apply($this, $this->lead, $correction)->assertForbidden();
    Koreksi::apply($this, $this->director, $correction)->assertForbidden();
    Koreksi::decline($this, $this->manager, $correction, 'Tidak sesuai')->assertForbidden();

    expect($correction->refresh()->status->value)->toBe('proposed');

    Koreksi::apply($this, $this->admin, $correction)->assertSessionHasNoErrors();

    expect($correction->refresh()->status->value)->toBe('applied');
});

it('applies a Superadmin correction at once, for anyone but themselves', function () {
    $shift = correctionShiftOf($this, $this->director);

    Koreksi::submit($this, $this->admin, $shift, 'clock_out_at', '2026-09-14 17:00')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    expect(Correction::query()->sole())
        ->status->value->toBe('applied')
        ->proposed_by->toBe($this->admin->id)
        ->approved_by->toBe($this->admin->id)
        ->and($shift->refresh()->regular_minutes)->toBe(480);
});

it('refuses every correction step on the actor\'s own records', function () {
    $adminShift = correctionShiftOf($this, $this->admin);

    // Own shift: no proposal, no direct correction
    Koreksi::submit($this, $this->admin, $adminShift, 'clock_out_at', '2026-09-14 17:00')
        ->assertSessionHasErrors(['correction' => 'Koreksi untuk catatanmu sendiri harus diajukan orang lain.']);

    // A proposal about the Superadmin, made by a Project Director, can only be decided by another Superadmin
    Koreksi::submit($this, $this->director, $adminShift, 'clock_out_at', '2026-09-14 17:00')->assertSessionHasNoErrors();
    $correction = Correction::query()->sole();

    Koreksi::apply($this, $this->admin, $correction)->assertSessionHasErrors(['correction' => 'Koreksi untuk catatanmu sendiri harus diterapkan Superadmin lain.']);
    Koreksi::decline($this, $this->admin, $correction, 'Saya tolak sendiri')->assertSessionHasErrors('correction');

    expect($correction->refresh()->status->value)->toBe('proposed')
        ->and($adminShift->refresh()->regular_minutes)->toBe(360);

    Koreksi::apply($this, $this->otherAdmin, $correction)->assertSessionHasNoErrors();

    expect($adminShift->refresh()->regular_minutes)->toBe(480);
});

it('refuses the preview and the shift list for people outside the scope', function () {
    $shift = correctionShiftOf($this, $this->outsider);

    Koreksi::preview($this, $this->lead, $shift, 'clock_out_at', '2026-09-14 17:00')->assertForbidden();
    $this->actingAs($this->lead)
        ->getJson(route('corrections.shifts', ['person_id' => $this->outsider->id, 'work_date' => '2026-09-14']))
        ->assertForbidden();
    $this->actingAs($this->lead)
        ->getJson(route('corrections.shifts', ['person_id' => $this->lead->id, 'work_date' => '2026-09-14']))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->getJson(route('corrections.shifts', ['person_id' => $this->outsider->id, 'work_date' => '2026-09-14']))
        ->assertOk()
        ->assertJsonPath('shifts.0.id', $shift->id)
        ->assertJsonPath('shifts.0.values.clock_out_at', Koreksi::iso('2026-09-14 15:00'))
        ->assertJsonPath('shifts.0.unavailable.overtime_ended_at', 'no_overtime');
});

it('shows Koreksi under Tim for Management and under Admin for Superadmin', function () {
    $keysIn = fn (User $user, string $group) => collect(collect($this->actingAs($user)->get(route('corrections.index'))->assertOk()
        ->viewData('page')['props']['nav'])->firstWhere('group', $group)['items'] ?? [])->pluck('key');

    expect($keysIn($this->lead, 'team'))->toContain('corrections')
        ->and($keysIn($this->admin, 'admin'))->toContain('corrections')
        ->and($keysIn($this->admin, 'team'))->not->toContain('corrections');

    $both = userWithRole(Role::Superadmin, Role::TeamLead);

    expect($keysIn($both, 'admin'))->toContain('corrections')
        ->and($keysIn($both, 'team'))->not->toContain('corrections');

    $this->actingAs(userWithRole(Role::Employee))->get(route('my-day'))
        ->assertInertia(fn ($page) => $page->where('nav', fn ($nav) => ! collect($nav)->flatMap(fn ($g) => $g['items'])->contains('key', 'corrections')));
});
