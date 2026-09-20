<?php

use App\Modules\Attendance\Models\Correction;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\Corrections\Support\Koreksi;
use Tests\TestCase;

// The Koreksi page: what each viewer sees (3.10). Monday 2026-09-14, Asia/Jakarta.

beforeEach(function () {
    $this->admin = userWithRole(Role::Superadmin);
    $this->lead = userWithRole(Role::TeamLead);
    $this->member = userWithRole(Role::Employee);
    $this->outsider = userWithRole(Role::Employee);
    $this->manager = userWithRole(Role::ProjectManager);
    Team::factory()->create(['name' => 'Animation', 'lead_user_id' => $this->lead->id])->members()->attach([$this->lead->id, $this->member->id]);
});

/** @return array<string, mixed> */
function correctionsPage(TestCase $test, User $viewer, array $query = []): array
{
    return $test->actingAs($viewer)->get(route('corrections.index', $query))->assertOk()
        ->assertInertia(fn ($page) => $page->component('corrections/Index'))
        ->viewData('page')['props'];
}

it('lists waiting proposals oldest first and decided ones newest first, in the viewer\'s scope', function () {
    $memberShift = Koreksi::workedShift($this, $this->member, '2026-09-14 09:00', '15:00');
    $outsiderShift = Koreksi::workedShift($this, $this->outsider, '2026-09-14 09:00', '15:00');

    $this->travelTo(Desk::time('2026-09-15 09:00'));
    Koreksi::submit($this, $this->lead, $memberShift, 'clock_out_at', '2026-09-14 17:00');
    $this->travelTo(Desk::time('2026-09-15 09:10'));
    Koreksi::submit($this, $this->manager, $outsiderShift, 'clock_out_at', '2026-09-14 16:00');
    $this->travelTo(Desk::time('2026-09-15 09:20'));
    Koreksi::submit($this, $this->manager, $memberShift, 'clock_in_at', '2026-09-14 08:30');
    $declined = Correction::query()->latest('id')->first();
    Koreksi::decline($this, $this->admin, $declined, 'Jam masuk sudah benar');

    $admin = correctionsPage($this, $this->admin);

    expect($admin['abilities'])->toBe(['apply' => true, 'propose' => false])
        ->and(collect($admin['waiting'])->pluck('person.id')->all())->toBe([$this->member->id, $this->outsider->id])
        ->and($admin['waiting'][0])->toMatchArray([
            'field' => 'clock_out_at',
            'old_value' => Koreksi::iso('2026-09-14 15:00'),
            'current_value' => Koreksi::iso('2026-09-14 15:00'),
            'changed_since' => false,
            'new_value' => Koreksi::iso('2026-09-14 17:00'),
            'reason' => Koreksi::REASON,
            'proposed_by' => $this->lead->name,
            'can_decide' => true,
        ])
        ->and(collect($admin['history'])->pluck('id')->all())->toBe([$declined->id])
        ->and($admin['history'][0])->toMatchArray(['status' => 'declined', 'decided_by' => $this->admin->name, 'decision_note' => 'Jam masuk sudah benar'])
        ->and(collect($admin['people'])->pluck('id'))->not->toContain($this->admin->id);

    $lead = correctionsPage($this, $this->lead);

    // The Team Lead sees their people's corrections, including one a Project Manager proposed, and nobody else's
    expect($lead['abilities'])->toBe(['apply' => false, 'propose' => true])
        ->and(collect($lead['waiting'])->pluck('person.id')->all())->toBe([$this->member->id])
        ->and($lead['waiting'][0]['can_decide'])->toBeFalse()
        ->and($lead['waiting'][0]['current_value'])->toBeNull()
        ->and(collect($lead['history'])->pluck('id')->all())->toBe([$declined->id])
        ->and(collect($lead['people'])->pluck('id')->all())->toBe([$this->member->id]);

    $filtered = correctionsPage($this, $this->admin, ['orang' => $this->outsider->id, 'status' => 'applied']);

    expect(collect($filtered['waiting'])->pluck('person.id')->all())->toBe([$this->outsider->id])
        ->and($filtered['history'])->toBe([])
        ->and($filtered['filters'])->toBe(['status' => 'applied', 'person' => $this->outsider->id]);
});

it('hides corrections of the viewer\'s own records', function () {
    $leadShift = Koreksi::workedShift($this, $this->lead, '2026-09-14 09:00', '15:00');
    $this->travelTo(Desk::time('2026-09-15 09:00'));
    Koreksi::submit($this, $this->manager, $leadShift, 'clock_out_at', '2026-09-14 17:00')->assertSessionHasNoErrors();

    expect(correctionsPage($this, $this->lead)['waiting'])->toBe([])
        ->and(correctionsPage($this, $this->manager)['waiting'])->toHaveCount(1);
});
