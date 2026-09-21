<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\WorkActivityLog;

function projectPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Film Pendek',
        'code' => 'FP-01',
        'status' => ProjectStatus::Active->value,
        'description' => null,
    ], $overrides);
}

function activityPayload(Project $project, array $overrides = []): array
{
    return array_merge([
        'project_id' => $project->id,
        'description' => 'Blocking shot hero walk cycle',
        'started_at' => '2026-09-21T09:00',
        'ended_at' => '2026-09-21T11:00',
        'evidence_url' => 'https://drive.example/folder/shot-a',
    ], $overrides);
}

describe('projects authorization', function () {
    it('lets managers create projects and refuses employees', function () {
        $lead = userWithRole(Role::TeamLead);
        $employee = userWithRole(Role::Employee);

        $this->actingAs($lead)->post(route('projects.store'), projectPayload())
            ->assertRedirect();

        expect(Project::query()->where('code', 'FP-01')->exists())->toBeTrue();

        $this->actingAs($employee)->post(route('projects.store'), projectPayload(['code' => 'FP-02']))
            ->assertForbidden();
    });

    it('shows assigned projects on my tasks', function () {
        $lead = userWithRole(Role::TeamLead);
        $employee = userWithRole(Role::Employee);
        $project = Project::query()->create(projectPayload());

        $this->actingAs($lead)->post(route('projects.assign', $project), ['user_id' => $employee->id])
            ->assertRedirect();

        $this->actingAs($employee)->get(route('projects.mine'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('projects/Mine')
                ->has('assignments', 1)
                ->where('assignments.0.project.id', $project->id));
    });
});

describe('work activity logs', function () {
    it('requires fields and allows overlapping intervals', function () {
        $user = userWithRole(Role::Employee);
        $project = Project::query()->create(projectPayload(['code' => 'ACT-1']));

        $this->actingAs($user)->post(route('activity.store'), activityPayload($project, [
            'description' => 'short',
            'evidence_url' => 'not-a-url',
            'ended_at' => '2026-09-21T08:00',
        ]))->assertSessionHasErrors(['description', 'evidence_url', 'ended_at']);

        $this->actingAs($user)->post(route('activity.store'), activityPayload($project))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)->post(route('activity.store'), activityPayload($project, [
            'started_at' => '2026-09-21T10:00',
            'ended_at' => '2026-09-21T12:00',
            'description' => 'Second overlapping pass on the same shot',
            'evidence_url' => 'https://drive.example/folder/shot-b',
        ]))->assertSessionHasNoErrors();

        expect(WorkActivityLog::query()->where('user_id', $user->id)->count())->toBe(2);
    });

    it('allows logging to a project the user is not assigned to', function () {
        $user = userWithRole(Role::Employee);
        $project = Project::query()->create(projectPayload(['code' => 'ACT-2']));

        $this->actingAs($user)->post(route('activity.store'), activityPayload($project))
            ->assertSessionHasNoErrors();

        expect(WorkActivityLog::query()->where('user_id', $user->id)->where('project_id', $project->id)->exists())->toBeTrue();
    });

    it('refuses editing someone else log', function () {
        $owner = userWithRole(Role::Employee);
        $other = userWithRole(Role::Employee);
        $project = Project::query()->create(projectPayload(['code' => 'ACT-3']));

        $this->actingAs($owner)->post(route('activity.store'), activityPayload($project))->assertSessionHasNoErrors();
        $log = WorkActivityLog::query()->where('user_id', $owner->id)->sole();

        $this->actingAs($other)->put(route('activity.update', $log), activityPayload($project, [
            'description' => 'Trying to rewrite someone else log entry',
        ]))->assertForbidden();
    });
});
