<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMilestone;
use App\Modules\Shared\Audit\AuditLog;
use Carbon\CarbonImmutable;

// docs/14 section 3.2: milestones on the project page, status worked out on read in the studio calendar.

beforeEach(function () {
    // 24 September in Jakarta already (03.00 WIB), still 23 September in UTC
    $this->travelTo(CarbonImmutable::parse('2026-09-23 20:00:00', 'UTC'));
    $this->project = Project::query()->create(['name' => 'Film Pendek', 'status' => ProjectStatus::Active]);
});

function milestonePayload(array $overrides = []): array
{
    return array_merge(['name' => 'Review animatic klien', 'kind' => 'client_review', 'due_date' => '2026-10-01', 'note' => null], $overrides);
}

function addMilestone(Project $project, string $name, string $due, bool $done = false): ProjectMilestone
{
    return $project->milestones()->create(['name' => $name, 'kind' => 'internal', 'due_date' => $due, 'done_at' => $done ? now() : null]);
}

it('works out the status in the studio calendar and sorts by date', function () {
    addMilestone($this->project, 'Jadwal', '2026-10-02');
    addMilestone($this->project, 'Batas segera', '2026-10-01');
    addMilestone($this->project, 'Hari ini', '2026-09-24');
    addMilestone($this->project, 'Kemarin UTC', '2026-09-23');
    addMilestone($this->project, 'Sudah dikirim', '2026-09-01', done: true);

    $this->actingAs(userWithRole(Role::Employee))->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/Show')
            ->has('milestones', 5)
            ->where('milestones.0.name', 'Sudah dikirim')
            ->where('milestones.0.status', 'done')
            ->where('milestones.1.name', 'Kemarin UTC')
            ->where('milestones.1.status', 'overdue')
            ->where('milestones.1.days_until', -1)
            ->where('milestones.2.status', 'soon')
            ->where('milestones.2.days_until', 0)
            ->where('milestones.3.status', 'soon')
            ->where('milestones.3.days_until', 7)
            ->where('milestones.4.status', 'scheduled')
            ->where('milestone_kinds', ['internal', 'client_review', 'delivery']));
});

it('lets project managers add, edit, complete, reopen, and delete, with audit', function () {
    $lead = userWithRole(Role::TeamLead);

    $this->actingAs($lead)->post(route('projects.milestones.store', $this->project), milestonePayload(['note' => '  Kirim link ke klien  ']))
        ->assertSessionHasNoErrors();
    $milestone = ProjectMilestone::query()->firstOrFail();
    expect($milestone)->name->toBe('Review animatic klien')->note->toBe('Kirim link ke klien')->created_by->toBe($lead->id);

    $this->actingAs($lead)->put(route('projects.milestones.update', [$this->project, $milestone]), milestonePayload(['name' => 'Review animatic', 'kind' => 'delivery', 'due_date' => '2026-10-05']))
        ->assertSessionHasNoErrors();
    expect($milestone->fresh())->name->toBe('Review animatic')->due_date->format('Y-m-d')->toBe('2026-10-05');

    $this->actingAs($lead)->post(route('projects.milestones.complete', [$this->project, $milestone]), ['done' => true]);
    expect($milestone->fresh()->done_at)->not->toBeNull();

    $this->actingAs($lead)->post(route('projects.milestones.complete', [$this->project, $milestone]), ['done' => false]);
    expect($milestone->fresh()->done_at)->toBeNull();

    $this->actingAs($lead)->delete(route('projects.milestones.destroy', [$this->project, $milestone]))->assertRedirect();
    expect(ProjectMilestone::query()->count())->toBe(0);

    expect(AuditLog::query()->where('action', 'like', 'milestone.%')->orderBy('id')->pluck('action')->all())
        ->toBe(['milestone.created', 'milestone.updated', 'milestone.completed', 'milestone.reopened', 'milestone.deleted']);
    expect(AuditLog::query()->where('action', 'milestone.updated')->first())
        ->before->toMatchArray(['name' => 'Review animatic klien', 'kind' => 'client_review', 'due_date' => '2026-10-01'])
        ->after->toMatchArray(['name' => 'Review animatic', 'kind' => 'delivery', 'due_date' => '2026-10-05']);
});

it('validates the form', function () {
    $this->actingAs(userWithRole(Role::ProjectManager))
        ->post(route('projects.milestones.store', $this->project), milestonePayload(['name' => '', 'kind' => 'party', 'due_date' => '01/10/2026']))
        ->assertSessionHasErrors(['name', 'kind', 'due_date']);
});

it('is read-only for employees and scoped to its project', function () {
    $employee = userWithRole(Role::Employee);
    $milestone = addMilestone($this->project, 'Kirim episode 1', '2026-10-10');
    $other = Project::query()->create(['name' => 'Iklan', 'status' => ProjectStatus::Active]);

    $this->actingAs($employee)->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->has('milestones', 1)->where('can_manage', false));

    $this->actingAs($employee)->post(route('projects.milestones.store', $this->project), milestonePayload())->assertForbidden();
    $this->actingAs($employee)->put(route('projects.milestones.update', [$this->project, $milestone]), milestonePayload())->assertForbidden();
    $this->actingAs($employee)->post(route('projects.milestones.complete', [$this->project, $milestone]), ['done' => true])->assertForbidden();
    $this->actingAs($employee)->delete(route('projects.milestones.destroy', [$this->project, $milestone]))->assertForbidden();

    $manager = userWithRole(Role::ProjectDirector);
    $this->actingAs($manager)->delete(route('projects.milestones.destroy', [$other, $milestone]))->assertNotFound();
    expect($milestone->fresh())->not->toBeNull();
});
