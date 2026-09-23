<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\PipelineStage;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\Task;
use App\Modules\Shared\Audit\AuditLog;

// docs/14 section 3.1: pipeline stages managed by PD and Superadmin, picked on tasks.

function stageNamed(string $name): PipelineStage
{
    return PipelineStage::query()->where('name', $name)->firstOrFail();
}

/** @return list<string> */
function stageOrder(string $phase): array
{
    return PipelineStage::query()->where('phase', $phase)->orderBy('sort')->orderBy('id')->pluck('name')->all();
}

describe('pipeline admin', function () {
    it('seeds the default stages in pipeline order', function () {
        expect(PipelineStage::query()->count())->toBe(16)
            ->and(stageOrder('pre_production'))->toBe(['Naskah', 'Desain karakter', 'Storyboard', 'Animatic'])
            ->and(stageOrder('post_production'))->toBe(['Compositing', 'Editing', 'Sound', 'Color grading']);
    });

    it('is open to Project Directors and Superadmins only', function () {
        foreach ([Role::Employee, Role::TeamLead, Role::ProjectManager] as $role) {
            $this->actingAs(userWithRole($role))->get(route('admin.pipeline.index'))->assertForbidden();
            $this->actingAs(userWithRole($role))->post(route('admin.pipeline.store'), ['name' => 'Previz', 'phase' => 'pre_production'])->assertForbidden();
        }

        foreach ([Role::ProjectDirector, Role::Superadmin] as $role) {
            $this->actingAs(userWithRole($role))->get(route('admin.pipeline.index'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('admin/pipeline/Index')
                    ->has('phases', 3)
                    ->where('phases.0.key', 'pre_production')
                    ->has('phases.1.stages', 8)
                    ->where('phases.1.stages.0.name', 'Modeling')
                    ->where('phases.1.stages.0.tasks_count', 0));
        }
    });

    it('adds a stage at the end of its phase and refuses a duplicate name', function () {
        $director = userWithRole(Role::ProjectDirector);

        $this->actingAs($director)->post(route('admin.pipeline.store'), ['name' => '  Previz ', 'phase' => 'pre_production'])
            ->assertSessionHasNoErrors();

        expect(stageOrder('pre_production'))->toBe(['Naskah', 'Desain karakter', 'Storyboard', 'Animatic', 'Previz'])
            ->and(AuditLog::query()->where('action', 'pipeline.stage_created')->first()->after)->toMatchArray(['phase' => 'pre_production', 'name' => 'Previz', 'sort' => 5]);

        $this->actingAs($director)->post(route('admin.pipeline.store'), ['name' => 'Rigging', 'phase' => 'post_production'])
            ->assertSessionHasErrors('name');
        $this->actingAs($director)->post(route('admin.pipeline.store'), ['name' => 'Tahap X', 'phase' => 'marketing'])
            ->assertSessionHasErrors('phase');
    });

    it('renames and switches a stage off and on, with audit', function () {
        $director = userWithRole(Role::ProjectDirector);
        $stage = stageNamed('FX');

        $this->actingAs($director)->put(route('admin.pipeline.update', $stage), ['name' => 'VFX', 'is_active' => false])
            ->assertSessionHasNoErrors();

        expect($stage->fresh())->name->toBe('VFX')->is_active->toBeFalse();
        $log = AuditLog::query()->where('action', 'pipeline.stage_updated')->firstOrFail();
        expect($log->before)->toBe(['name' => 'FX', 'is_active' => true])
            ->and($log->after)->toBe(['name' => 'VFX', 'is_active' => false]);

        $this->actingAs($director)->put(route('admin.pipeline.update', $stage), ['name' => 'VFX', 'is_active' => true]);
        expect($stage->fresh()->is_active)->toBeTrue();

        // The phase is fixed once a stage exists
        $this->actingAs($director)->put(route('admin.pipeline.update', $stage), ['name' => 'VFX', 'phase' => 'pre_production'])
            ->assertSessionHasErrors('phase');
    });

    it('moves a stage up and down inside its phase', function () {
        $admin = userWithRole(Role::Superadmin);

        $this->actingAs($admin)->post(route('admin.pipeline.move', stageNamed('Storyboard')), ['direction' => 'up'])->assertRedirect();
        expect(stageOrder('pre_production'))->toBe(['Naskah', 'Storyboard', 'Desain karakter', 'Animatic']);

        $this->actingAs($admin)->post(route('admin.pipeline.move', stageNamed('Naskah')), ['direction' => 'down']);
        expect(stageOrder('pre_production'))->toBe(['Storyboard', 'Naskah', 'Desain karakter', 'Animatic']);

        $log = AuditLog::query()->where('action', 'pipeline.stage_reordered')->latest('id')->firstOrFail();
        expect($log->after['phase'])->toBe('pre_production')
            ->and($log->after['order'])->toBe(PipelineStage::query()->where('phase', 'pre_production')->orderBy('sort')->pluck('id')->all());

        // The edges stay put, and other phases are untouched
        $this->actingAs($admin)->post(route('admin.pipeline.move', stageNamed('Storyboard')), ['direction' => 'up']);
        $this->actingAs($admin)->post(route('admin.pipeline.move', stageNamed('Animatic')), ['direction' => 'down']);
        expect(stageOrder('pre_production'))->toBe(['Storyboard', 'Naskah', 'Desain karakter', 'Animatic'])
            ->and(stageOrder('production')[0])->toBe('Modeling')
            ->and(AuditLog::query()->where('action', 'pipeline.stage_reordered')->count())->toBe(2);

        $this->actingAs($admin)->post(route('admin.pipeline.move', stageNamed('Naskah')), ['direction' => 'sideways'])
            ->assertSessionHasErrors('direction');
    });

    it('deletes an unused stage and refuses one that a task uses', function () {
        $director = userWithRole(Role::ProjectDirector);
        $project = Project::query()->create(['name' => 'Film Pendek', 'status' => ProjectStatus::Active]);
        $sub = $project->subProjects()->create(['name' => 'Episode 1', 'status' => ProjectStatus::Active]);
        Task::query()->create([
            'project_id' => $project->id, 'sub_project_id' => $sub->id, 'stage_id' => stageNamed('Rigging')->id,
            'title' => 'Rig Nara', 'status' => TaskStatus::Todo, 'priority' => 'normal', 'created_by' => $director->id,
        ]);

        $this->actingAs($director)->get(route('admin.pipeline.index'))
            ->assertInertia(fn ($page) => $page->where('phases.1.stages.2.name', 'Rigging')->where('phases.1.stages.2.tasks_count', 1));

        $this->actingAs($director)->delete(route('admin.pipeline.destroy', stageNamed('Rigging')))
            ->assertSessionHasErrors('stage');
        expect(PipelineStage::query()->where('name', 'Rigging')->exists())->toBeTrue();

        $this->actingAs($director)->delete(route('admin.pipeline.destroy', stageNamed('Sound')))
            ->assertSessionHasNoErrors();
        expect(PipelineStage::query()->where('name', 'Sound')->exists())->toBeFalse()
            ->and(AuditLog::query()->where('action', 'pipeline.stage_deleted')->first()->before)->toMatchArray(['phase' => 'post_production', 'name' => 'Sound']);

        $this->actingAs(userWithRole(Role::ProjectManager))->delete(route('admin.pipeline.destroy', stageNamed('Editing')))->assertForbidden();
    });

    it('counts a deleted task as using its stage, so the stage cannot go', function () {
        $director = userWithRole(Role::ProjectDirector);
        $project = Project::query()->create(['name' => 'Film Pendek', 'status' => ProjectStatus::Active]);
        $sub = $project->subProjects()->create(['name' => 'Episode 1', 'status' => ProjectStatus::Active]);
        Task::query()->create([
            'project_id' => $project->id, 'sub_project_id' => $sub->id, 'stage_id' => stageNamed('Sound')->id,
            'title' => 'Foley shot 010', 'status' => TaskStatus::Todo, 'priority' => 'normal', 'created_by' => $director->id,
        ])->delete();

        $this->actingAs($director)->get(route('admin.pipeline.index'))
            ->assertInertia(fn ($page) => $page->where('phases.2.stages.2.name', 'Sound')->where('phases.2.stages.2.tasks_count', 1));

        $this->actingAs($director)->delete(route('admin.pipeline.destroy', stageNamed('Sound')))
            ->assertSessionHasErrors('stage');
        expect(PipelineStage::query()->where('name', 'Sound')->exists())->toBeTrue()
            ->and(Task::withTrashed()->sole()->stage_id)->toBe(stageNamed('Sound')->id);
    });
});

describe('stages on tasks', function () {
    beforeEach(function () {
        $this->lead = userWithRole(Role::TeamLead);
        $this->member = userWithRole(Role::Employee);
        $this->project = Project::query()->create(['name' => 'Film Pendek', 'status' => ProjectStatus::Active]);
        ProjectMember::query()->create(['project_id' => $this->project->id, 'user_id' => $this->member->id, 'assigned_at' => now()]);
        $this->sub = $this->project->subProjects()->create(['name' => 'Episode 1', 'status' => ProjectStatus::Active, 'lead_user_id' => $this->lead->id]);
    });

    it('lets a lead and a proposer pick an active stage and refuses an inactive one on a new task', function () {
        $url = route('projects.tasks.store', [$this->project, $this->sub]);
        $animation = stageNamed('Animasi');
        $fx = stageNamed('FX');
        $fx->forceFill(['is_active' => false])->save();

        $this->actingAs($this->lead)->post($url, ['title' => 'Blocking shot 010', 'priority' => 'normal', 'stage_id' => $animation->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->member)->post($url, ['title' => 'Layout shot 020', 'priority' => 'normal', 'stage_id' => stageNamed('Layout')->id])
            ->assertSessionHasNoErrors();

        expect(Task::query()->where('title', 'Blocking shot 010')->value('stage_id'))->toBe($animation->id)
            ->and(Task::query()->where('title', 'Layout shot 020')->first())
            ->status->toBe(TaskStatus::Proposed)
            ->stage_id->toBe(stageNamed('Layout')->id);

        $this->actingAs($this->lead)->post($url, ['title' => 'Asap ledakan', 'priority' => 'normal', 'stage_id' => $fx->id])
            ->assertSessionHasErrors('stage_id');
        $this->actingAs($this->lead)->post($url, ['title' => 'Asap ledakan', 'priority' => 'normal', 'stage_id' => 999999])
            ->assertSessionHasErrors('stage_id');
    });

    it('keeps an inactive stage on edit, refuses switching to another inactive one, and audits the stage', function () {
        $fx = stageNamed('FX');
        $lighting = stageNamed('Lighting');
        $task = Task::query()->create([
            'project_id' => $this->project->id, 'sub_project_id' => $this->sub->id, 'stage_id' => $fx->id,
            'title' => 'Asap ledakan', 'status' => TaskStatus::Todo, 'priority' => 'normal', 'created_by' => $this->lead->id,
        ]);
        $fx->forceFill(['is_active' => false])->save();
        $lighting->forceFill(['is_active' => false])->save();

        $this->actingAs($this->lead)->put(route('tasks.update', $task), ['title' => 'Asap ledakan besar', 'priority' => 'high', 'stage_id' => $fx->id])
            ->assertSessionHasNoErrors();
        expect($task->fresh()->stage_id)->toBe($fx->id);

        $this->actingAs($this->lead)->put(route('tasks.update', $task), ['title' => 'Asap ledakan besar', 'priority' => 'high', 'stage_id' => $lighting->id])
            ->assertSessionHasErrors('stage_id');

        $this->actingAs($this->lead)->put(route('tasks.update', $task), ['title' => 'Asap ledakan besar', 'priority' => 'high', 'stage_id' => stageNamed('Render')->id])
            ->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('action', 'task.updated')->latest('id')->firstOrFail();
        expect($log->before['stage_id'])->toBe($fx->id)
            ->and($log->after['stage_id'])->toBe(stageNamed('Render')->id);

        // A form without the field leaves the stage alone
        $this->actingAs($this->lead)->put(route('tasks.update', $task), ['title' => 'Asap ledakan besar', 'priority' => 'high']);
        expect($task->fresh()->stage_id)->toBe(stageNamed('Render')->id);
    });

    it('shows the stage on the sub project page, the task page, and Tugas saya', function () {
        $stage = stageNamed('Animasi');
        $task = Task::query()->create([
            'project_id' => $this->project->id, 'sub_project_id' => $this->sub->id, 'stage_id' => $stage->id, 'assignee_id' => $this->member->id,
            'title' => 'Blocking shot 010', 'status' => TaskStatus::Todo, 'priority' => 'normal', 'created_by' => $this->lead->id,
        ]);
        $expected = ['id' => $stage->id, 'name' => 'Animasi', 'phase' => 'production', 'is_active' => true];

        $this->actingAs($this->member)->get(route('projects.sub.show', [$this->project, $this->sub]))
            ->assertInertia(fn ($page) => $page->where('tasks.0.stage', $expected)->has('stages', 16)->where('stages.0.name', 'Naskah'));
        $this->actingAs($this->member)->get(route('tasks.show', $task))
            ->assertInertia(fn ($page) => $page->where('task.stage', $expected)->has('stages', 16));
        $this->actingAs($this->member)->get(route('projects.mine'))
            ->assertInertia(fn ($page) => $page->where('my_tasks.0.stage', $expected));
    });
});
