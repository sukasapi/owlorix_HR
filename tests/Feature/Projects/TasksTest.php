<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\ReviewStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskAssignee;
use App\Modules\Projects\Models\TaskWorkSession;
use App\Modules\Projects\Models\WorkActivityLog;
use App\Modules\Shared\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// docs/13: sub projects, tasks proposed by members and decided by the sub project lead, timer, evidence, review.

beforeEach(function () {
    $this->lead = userWithRole(Role::TeamLead);
    $this->otherLead = userWithRole(Role::TeamLead);
    $this->manager = userWithRole(Role::ProjectManager);
    $this->member = userWithRole(Role::Employee);
    $this->outsider = userWithRole(Role::Employee);

    $this->project = Project::query()->create(['name' => 'Film Pendek', 'code' => 'FP', 'status' => ProjectStatus::Active]);
    ProjectMember::query()->create(['project_id' => $this->project->id, 'user_id' => $this->member->id, 'assigned_at' => now()]);
    $this->sub = $this->project->subProjects()->create(['name' => 'Episode 1', 'status' => ProjectStatus::Active, 'lead_user_id' => $this->lead->id]);
});

function taskPayload(array $overrides = []): array
{
    return array_merge(['title' => 'Rigging wajah Nara', 'description' => null, 'priority' => 'normal'], $overrides);
}

function proposeTask($test, array $overrides = []): Task
{
    $test->actingAs($test->member)
        ->post(route('projects.tasks.store', [$test->project, $test->sub]), taskPayload($overrides))
        ->assertSessionHasNoErrors();

    return Task::query()->latest('id')->firstOrFail();
}

describe('sub projects', function () {
    it('lets managers create a sub project with a lead who manages projects', function () {
        $this->actingAs($this->lead)
            ->post(route('projects.sub.store', $this->project), ['name' => 'Episode 2', 'status' => 'active', 'lead_user_id' => $this->otherLead->id])
            ->assertRedirect();

        expect(SubProject::query()->where('name', 'Episode 2')->value('lead_user_id'))->toBe($this->otherLead->id);
    });

    it('refuses a lead without project management rights and refuses employees', function () {
        $this->actingAs($this->lead)
            ->post(route('projects.sub.store', $this->project), ['name' => 'Episode 2', 'status' => 'active', 'lead_user_id' => $this->member->id])
            ->assertSessionHasErrors('lead_user_id');

        $this->actingAs($this->member)
            ->post(route('projects.sub.store', $this->project), ['name' => 'Episode 3', 'status' => 'active'])
            ->assertForbidden();
    });

    it('shows the task list and refuses a sub project from another project', function () {
        $other = Project::query()->create(['name' => 'Iklan', 'status' => ProjectStatus::Active]);

        $this->actingAs($this->member)->get(route('projects.sub.show', [$this->project, $this->sub]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('projects/SubProject')
                ->where('can.propose_task', true)
                ->where('can.create_task', false));

        $this->actingAs($this->member)->get(route('projects.sub.show', [$other, $this->sub]))->assertNotFound();
    });
});

describe('proposals', function () {
    it('makes a member task a proposal assigned to the proposer, and a lead task a todo', function () {
        $proposal = proposeTask($this);

        expect($proposal->status)->toBe(TaskStatus::Proposed)
            ->and($proposal->assignees()->pluck('users.id')->all())->toBe([$this->member->id])
            ->and(AuditLog::query()->where('action', 'task.proposed')->exists())->toBeTrue();

        $this->actingAs($this->lead)
            ->post(route('projects.tasks.store', [$this->project, $this->sub]), taskPayload(['title' => 'Lighting shot 010', 'assignee_ids' => [$this->member->id]]))
            ->assertSessionHasNoErrors();

        expect(Task::query()->where('title', 'Lighting shot 010')->value('status'))->toBe(TaskStatus::Todo);
    });

    it('refuses proposals from people outside the project', function () {
        $this->actingAs($this->outsider)
            ->post(route('projects.tasks.store', [$this->project, $this->sub]), taskPayload())
            ->assertForbidden();
    });

    it('lets only the sub project lead or an overseer decide, and needs a reason to reject', function () {
        $proposal = proposeTask($this);

        $this->actingAs($this->otherLead)->post(route('tasks.decide', $proposal), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->member)->post(route('tasks.decide', $proposal), ['decision' => 'approve'])->assertForbidden();

        $this->actingAs($this->lead)->post(route('tasks.decide', $proposal), ['decision' => 'reject', 'note' => 'short'])->assertSessionHasErrors('note');

        $this->actingAs($this->lead)
            ->post(route('tasks.decide', $proposal), ['decision' => 'reject', 'note' => 'Sudah dikerjakan di Episode 2.'])
            ->assertSessionHasNoErrors();

        expect($proposal->refresh())
            ->status->toBe(TaskStatus::Rejected)
            ->decided_by->toBe($this->lead->id)
            ->decision_note->toBe('Sudah dikerjakan di Episode 2.');

        $second = proposeTask($this, ['title' => 'Blendshape mulut']);
        $this->actingAs($this->manager)->post(route('tasks.decide', $second), ['decision' => 'approve'])->assertSessionHasNoErrors();

        expect($second->refresh()->status)->toBe(TaskStatus::Todo);
    });

    it('counts proposals and evidence waiting for the lead in the nav badge', function () {
        proposeTask($this);

        $this->actingAs($this->lead)->get(route('projects.mine'))
            ->assertInertia(fn ($page) => $page->where('nav_badges.my_tasks', 1)->has('waiting', 1));

        $this->actingAs($this->otherLead)->get(route('projects.mine'))
            ->assertInertia(fn ($page) => $page->missing('nav_badges.my_tasks')->has('waiting', 0));
    });
});

describe('timer, evidence, and work log', function () {
    beforeEach(function () {
        $this->task = Task::query()->create([
            'project_id' => $this->project->id,
            'sub_project_id' => $this->sub->id,
            'title' => 'Animasi shot 010_020',
            'status' => TaskStatus::Todo,
            'priority' => 'normal',
            'created_by' => $this->lead->id,
            'evidence_required' => true,
        ]);
        TaskAssignee::query()->create(['task_id' => $this->task->id, 'user_id' => $this->member->id, 'part_status' => 'open', 'assigned_at' => now()]);
    });

    it('runs one timer per person and moves the task to in progress', function () {
        $other = $this->task->replicate()->fill(['title' => 'Animasi shot 010_030']);
        $other->save();
        TaskAssignee::query()->create(['task_id' => $other->id, 'user_id' => $this->member->id, 'part_status' => 'open', 'assigned_at' => now()]);

        $this->travelTo(CarbonImmutable::parse('2026-09-23 02:00:00'));
        $this->actingAs($this->member)->post(route('tasks.start', $this->task))->assertRedirect();
        $this->travelTo(CarbonImmutable::parse('2026-09-23 03:00:00'));
        $this->actingAs($this->member)->post(route('tasks.start', $other))->assertRedirect();

        expect(TaskWorkSession::query()->whereNull('ended_at')->count())->toBe(1)
            ->and(TaskWorkSession::query()->where('task_id', $this->task->id)->first()->minutes())->toBe(60)
            ->and($this->task->refresh()->status)->toBe(TaskStatus::InProgress);

        $this->actingAs($this->outsider)->post(route('tasks.start', $this->task))->assertForbidden();
    });

    it('needs evidence when the task requires it', function () {
        $this->actingAs($this->member)
            ->post(route('tasks.submit', $this->task), ['note' => 'Blocking dan spline selesai.'])
            ->assertSessionHasErrors('evidence_url');
    });

    it('turns timer sessions into work log rows when evidence is sent', function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 02:00:00'));
        $this->actingAs($this->member)->post(route('tasks.start', $this->task));
        $this->travelTo(CarbonImmutable::parse('2026-09-23 04:00:00'));
        $this->actingAs($this->member)->post(route('tasks.stop'));
        $this->travelTo(CarbonImmutable::parse('2026-09-23 05:00:00'));
        $this->actingAs($this->member)->post(route('tasks.start', $this->task));
        $this->travelTo(CarbonImmutable::parse('2026-09-23 05:30:00'));

        $this->actingAs($this->member)
            ->post(route('tasks.submit', $this->task), [
                'note' => 'Blocking dan spline selesai.',
                'evidence_url' => 'https://drive.example/shot-010-020',
            ])
            ->assertSessionHasNoErrors();

        $logs = WorkActivityLog::query()->where('task_id', $this->task->id)->orderBy('started_at')->get();

        expect($this->task->refresh()->status)->toBe(TaskStatus::InReview)
            ->and(TaskWorkSession::query()->whereNull('ended_at')->count())->toBe(0)
            ->and($logs)->toHaveCount(2)
            ->and($logs[0]->project_id)->toBe($this->project->id)
            ->and($logs[0]->user_id)->toBe($this->member->id)
            ->and($logs[0]->description)->toBe('Animasi shot 010_020: Blocking dan spline selesai.')
            ->and($logs[0]->evidence_url)->toBe('https://drive.example/shot-010-020')
            ->and($logs[0]->started_at->toIso8601ZuluString())->toBe('2026-09-23T02:00:00Z')
            ->and($logs[1]->ended_at->toIso8601ZuluString())->toBe('2026-09-23T05:30:00Z')
            ->and(TaskWorkSession::query()->whereNull('work_activity_log_id')->count())->toBe(0);
    });

    it('uses the manual time range when no timer ran, and stores an uploaded file privately', function () {
        Storage::fake('local');

        $this->actingAs($this->member)
            ->post(route('tasks.submit', $this->task), [
                'note' => 'Playblast final shot 010_020.',
                'evidence_file' => UploadedFile::fake()->create('playblast.mp4', 500, 'video/mp4'),
                'worked_from' => '2026-09-22T09:00',
                'worked_until' => '2026-09-22T12:00',
            ])
            ->assertSessionHasNoErrors();

        $log = WorkActivityLog::query()->where('task_id', $this->task->id)->sole();
        $submission = $this->task->submissions()->sole();

        expect($log->started_at->toIso8601ZuluString())->toBe('2026-09-22T02:00:00Z')
            ->and($log->evidence_url)->toBe(route('tasks.evidence', $submission))
            ->and($submission->file_name)->toBe('playblast.mp4');
        Storage::disk('local')->assertExists($submission->file_path);

        $this->actingAs($this->outsider)->get(route('tasks.evidence', $submission))->assertOk();
    });

    it('lets the lead approve or ask for changes, not the assignee', function () {
        $this->actingAs($this->member)->post(route('tasks.submit', $this->task), [
            'note' => 'Blocking dan spline selesai.',
            'evidence_url' => 'https://drive.example/shot-010-020',
        ]);

        $first = $this->task->submissions()->sole();

        $this->actingAs($this->member)->post(route('tasks.review', $this->task), ['decision' => 'approve', 'submission_id' => $first->id])->assertForbidden();
        $this->actingAs($this->lead)->post(route('tasks.review', $this->task), ['decision' => 'approve'])->assertSessionHasErrors('submission_id');
        $this->actingAs($this->lead)->post(route('tasks.review', $this->task), ['decision' => 'changes', 'submission_id' => $first->id])->assertSessionHasErrors('note');

        $this->actingAs($this->lead)
            ->post(route('tasks.review', $this->task), ['decision' => 'changes', 'submission_id' => $first->id, 'note' => 'Tangan kiri masih menembus badan.'])
            ->assertSessionHasNoErrors();

        expect($this->task->refresh()->status)->toBe(TaskStatus::ChangesRequested)
            ->and($this->task->submissions()->sole()->review_status)->toBe(ReviewStatus::ChangesRequested);

        $this->actingAs($this->member)->post(route('tasks.submit', $this->task), [
            'note' => 'Tangan kiri sudah diperbaiki.',
            'evidence_url' => 'https://drive.example/shot-010-020-v2',
        ])->assertSessionHasNoErrors();
        $second = $this->task->submissions()->latest('id')->first();
        $this->actingAs($this->lead)->post(route('tasks.review', $this->task), ['decision' => 'approve', 'submission_id' => $second->id])->assertSessionHasNoErrors();

        expect($this->task->refresh())
            ->status->toBe(TaskStatus::Done)
            ->completed_at->not->toBeNull();
    });

    it('lets a member take an unassigned task', function () {
        $this->task->parts()->delete();

        $this->actingAs($this->outsider)->post(route('tasks.claim', $this->task))->assertForbidden();
        $this->actingAs($this->member)->post(route('tasks.claim', $this->task))->assertRedirect();

        expect($this->task->assignees()->pluck('users.id')->all())->toBe([$this->member->id]);
    });
});
