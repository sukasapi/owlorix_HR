<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\PartStatus;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\ReviewStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskAssignee;
use App\Modules\Projects\Models\TaskSubmission;
use App\Modules\Projects\Models\TaskWorkSession;
use App\Modules\Projects\Models\WorkActivityLog;
use App\Modules\Projects\Services\TaskParts;
use App\Modules\Projects\Services\TaskTimer;
use App\Modules\Projects\Services\TaskWorkflow;
use App\Modules\Shared\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

// docs/15: one task, several assignees. Each person has a part with their own timer, evidence, and review; the task
// status follows the parts.

beforeEach(function () {
    $this->lead = userWithRole(Role::TeamLead);
    $this->manager = userWithRole(Role::ProjectManager);
    $this->rani = userWithRole(Role::Employee);
    $this->bayu = userWithRole(Role::Employee);
    $this->citra = userWithRole(Role::Employee);
    $this->outsider = userWithRole(Role::Employee);

    $this->project = Project::query()->create(['name' => 'Film Pendek', 'code' => 'FP', 'status' => ProjectStatus::Active]);
    foreach ([$this->rani, $this->bayu, $this->citra] as $member) {
        ProjectMember::query()->create(['project_id' => $this->project->id, 'user_id' => $member->id, 'assigned_at' => now()]);
    }
    $this->sub = $this->project->subProjects()->create(['name' => 'Episode 1', 'status' => ProjectStatus::Active, 'lead_user_id' => $this->lead->id]);
});

/** @param  list<User>  $people */
function sharedTask($test, array $people, TaskStatus $status = TaskStatus::Todo, array $extra = []): Task
{
    $task = Task::query()->create([
        'project_id' => $test->project->id,
        'sub_project_id' => $test->sub->id,
        'title' => 'Animasi shot 010_020',
        'status' => $status,
        'priority' => 'normal',
        'created_by' => $test->lead->id,
        'evidence_required' => true,
        ...$extra,
    ]);

    foreach ($people as $person) {
        TaskAssignee::query()->create(['task_id' => $task->id, 'user_id' => $person->id, 'part_status' => PartStatus::Open, 'assigned_by' => $test->lead->id, 'assigned_at' => now()]);
    }

    return $task;
}

function sendEvidence($test, User $person, Task $task)
{
    return $test->actingAs($person)->post(route('tasks.submit', $task), [
        'note' => 'Blocking dan spline selesai.',
        'evidence_url' => 'https://drive.example/shot-'.$person->id,
    ]);
}

function latestSubmission(Task $task, User $person): TaskSubmission
{
    return TaskSubmission::query()->where('task_id', $task->id)->where('submitted_by', $person->id)->latest('id')->firstOrFail();
}

function partOf(Task $task, User $person): ?PartStatus
{
    return TaskAssignee::query()->where('task_id', $task->id)->where('user_id', $person->id)->first()?->part_status;
}

describe('status from the parts', function () {
    it('follows the table in docs/15 section 2', function (array $parts, bool $started, TaskStatus $expected) {
        expect(TaskParts::resolve(array_map(fn (string $p) => PartStatus::from($p), $parts), $started))->toBe($expected);
    })->with([
        'nobody on it' => [[], false, TaskStatus::Todo],
        'nobody on it, sessions left behind' => [[], true, TaskStatus::Todo],
        'all approved' => [['approved', 'approved'], true, TaskStatus::Done],
        'one approved alone' => [['approved'], true, TaskStatus::Done],
        'changes requested beats open' => [['changes_requested', 'open'], true, TaskStatus::ChangesRequested],
        'changes requested beats submitted' => [['changes_requested', 'submitted', 'approved'], true, TaskStatus::ChangesRequested],
        'open and started' => [['open', 'submitted'], true, TaskStatus::InProgress],
        'open, nobody started' => [['open', 'open'], false, TaskStatus::Todo],
        'everyone sent' => [['submitted', 'submitted'], true, TaskStatus::InReview],
        'sent and approved' => [['submitted', 'approved'], true, TaskStatus::InReview],
    ]);

    it('counts a timer session, a submission, or a reviewed part as started, and leaves proposals alone', function () {
        $parts = app(TaskParts::class);
        $task = sharedTask($this, [$this->rani, $this->bayu]);

        expect($parts->recompute($task))->toBe(TaskStatus::Todo);

        TaskWorkSession::query()->create(['task_id' => $task->id, 'user_id' => $this->citra->id, 'started_at' => now()->subHour(), 'ended_at' => now()]);
        expect($parts->recompute($task))->toBe(TaskStatus::InProgress);

        $proposal = sharedTask($this, [$this->rani], TaskStatus::Proposed);
        $proposal->parts()->update(['part_status' => PartStatus::Approved->value]);
        expect($parts->recompute($proposal))->toBe(TaskStatus::Proposed)
            ->and($proposal->refresh()->completed_at)->toBeNull();
    });
});

describe('assigning', function () {
    it('lets a lead put several people on a new task, each with an open part', function () {
        $this->actingAs($this->lead)
            ->post(route('projects.tasks.store', [$this->project, $this->sub]), [
                'title' => 'Lighting shot 010', 'priority' => 'normal', 'assignee_ids' => [$this->rani->id, $this->bayu->id],
            ])
            ->assertSessionHasNoErrors();

        $task = Task::query()->where('title', 'Lighting shot 010')->sole();

        expect($task->status)->toBe(TaskStatus::Todo)
            ->and($task->assignees()->pluck('users.id')->all())->toBe([$this->rani->id, $this->bayu->id])
            ->and($task->parts()->orderBy('id')->pluck('part_status')->all())->toBe([PartStatus::Open, PartStatus::Open])
            ->and(AuditLog::query()->where('action', 'task.created')->sole()->after['assignee_ids'])->toBe([$this->rani->id, $this->bayu->id]);
    });

    it('refuses more than 20 people and inactive ones', function () {
        $many = User::factory()->count(21)->create()->modelKeys();

        $this->actingAs($this->lead)
            ->post(route('projects.tasks.store', [$this->project, $this->sub]), ['title' => 'Crowd shot', 'priority' => 'normal', 'assignee_ids' => $many])
            ->assertSessionHasErrors('assignee_ids');

        $left = User::factory()->create(['status' => UserStatus::Left]);
        $this->actingAs($this->lead)
            ->post(route('projects.tasks.store', [$this->project, $this->sub]), ['title' => 'Crowd shot', 'priority' => 'normal', 'assignee_ids' => [$this->rani->id, $left->id]])
            ->assertSessionHasErrors('assignee_ids.1');
    });

    it('stops the running timer of a removed person, keeps their sessions, and writes the change to the audit log', function () {
        $task = sharedTask($this, [$this->rani, $this->bayu]);
        $this->travelTo(CarbonImmutable::parse('2026-09-24 02:00:00'));
        $this->actingAs($this->bayu)->post(route('tasks.start', $task))->assertRedirect();
        $this->travelTo(CarbonImmutable::parse('2026-09-24 03:30:00'));

        $this->actingAs($this->lead)
            ->put(route('tasks.update', $task), ['title' => $task->title, 'priority' => 'normal', 'assignee_ids' => [$this->rani->id, $this->citra->id]])
            ->assertSessionHasNoErrors();

        $session = TaskWorkSession::query()->where('user_id', $this->bayu->id)->sole();
        $audit = AuditLog::query()->where('action', 'task.assignees_changed')->sole();

        expect($session->ended_at->toIso8601ZuluString())->toBe('2026-09-24T03:30:00Z')
            ->and($session->work_activity_log_id)->toBeNull()
            ->and(partOf($task, $this->bayu))->toBeNull()
            ->and(partOf($task, $this->citra))->toBe(PartStatus::Open)
            ->and($audit->before)->toBe(['assignee_ids' => [$this->rani->id, $this->bayu->id]])
            ->and($audit->after['assignee_ids'])->toBe([$this->rani->id, $this->citra->id])
            ->and($audit->after['assignees_added'])->toBe([$this->citra->id])
            ->and($audit->after['assignees_removed'])->toBe([$this->bayu->id]);
    });

    it('keeps the assignees when the form leaves the list out', function () {
        $task = sharedTask($this, [$this->rani, $this->bayu]);

        $this->actingAs($this->lead)->put(route('tasks.update', $task), ['title' => 'Animasi shot 010_020 v2', 'priority' => 'high'])->assertSessionHasNoErrors();

        expect($task->assignees()->pluck('users.id')->all())->toBe([$this->rani->id, $this->bayu->id])
            ->and(AuditLog::query()->where('action', 'task.assignees_changed')->exists())->toBeFalse();
    });

    it('reopens a finished task when someone is added', function () {
        $task = sharedTask($this, [$this->rani], TaskStatus::Done, ['completed_at' => now()]);
        $task->parts()->update(['part_status' => PartStatus::Approved->value]);
        TaskSubmission::query()->create(['task_id' => $task->id, 'submitted_by' => $this->rani->id, 'note' => 'Render final', 'review_status' => ReviewStatus::Approved]);

        $this->actingAs($this->lead)
            ->put(route('tasks.update', $task), ['title' => $task->title, 'priority' => 'normal', 'assignee_ids' => [$this->rani->id, $this->bayu->id]])
            ->assertSessionHasNoErrors();

        expect($task->refresh())
            ->status->toBe(TaskStatus::InProgress)
            ->completed_at->toBeNull()
            ->and(partOf($task, $this->bayu))->toBe(PartStatus::Open);
    });

    it('lets the lead add people when approving a proposal', function () {
        $this->actingAs($this->rani)
            ->post(route('projects.tasks.store', [$this->project, $this->sub]), ['title' => 'Rigging wajah Nara', 'priority' => 'normal', 'assignee_ids' => [$this->bayu->id]])
            ->assertSessionHasNoErrors();
        $proposal = Task::query()->where('title', 'Rigging wajah Nara')->sole();

        // A proposer cannot pick others: the proposal is their own work
        expect($proposal->assignees()->pluck('users.id')->all())->toBe([$this->rani->id]);

        $this->actingAs($this->lead)
            ->post(route('tasks.decide', $proposal), ['decision' => 'approve', 'assignee_ids' => [$this->rani->id, $this->citra->id]])
            ->assertSessionHasNoErrors();

        expect($proposal->refresh()->status)->toBe(TaskStatus::Todo)
            ->and($proposal->assignees()->pluck('users.id')->all())->toBe([$this->rani->id, $this->citra->id]);
    });
});

describe('claiming', function () {
    it('lets a member take only a task nobody is on', function () {
        $taken = sharedTask($this, [$this->rani]);
        $free = sharedTask($this, []);

        $this->actingAs($this->bayu)->post(route('tasks.claim', $taken))->assertForbidden();
        $this->actingAs($this->bayu)->post(route('tasks.claim', $free))->assertRedirect();

        $audit = AuditLog::query()->where('action', 'task.claimed')->sole();

        expect($free->assignees()->pluck('users.id')->all())->toBe([$this->bayu->id])
            ->and($taken->assignees()->pluck('users.id')->all())->toBe([$this->rani->id])
            ->and($audit->after['person_id'])->toBe($this->bayu->id);
    });
});

describe('working on a part', function () {
    it('lets only people whose own part is open or needs changes run the timer', function () {
        $task = sharedTask($this, [$this->rani, $this->bayu]);
        sendEvidence($this, $this->bayu, $task)->assertSessionHasNoErrors();

        $this->actingAs($this->outsider)->post(route('tasks.start', $task))->assertForbidden();
        $this->actingAs($this->citra)->post(route('tasks.start', $task))->assertForbidden();
        $this->actingAs($this->bayu)->post(route('tasks.start', $task))->assertForbidden();
        sendEvidence($this, $this->bayu, $task)->assertForbidden();
        $this->actingAs($this->rani)->post(route('tasks.start', $task))->assertRedirect();

        $task->parts()->where('user_id', $this->bayu->id)->update(['part_status' => PartStatus::ChangesRequested->value]);
        $this->actingAs($this->bayu)->post(route('tasks.start', $task))->assertRedirect();

        expect(TaskWorkSession::query()->where('task_id', $task->id)->whereNull('ended_at')->pluck('user_id')->sort()->values()->all())
            ->toBe(collect([$this->rani->id, $this->bayu->id])->sort()->values()->all());
    });

    it('turns only the sender\'s own sessions into work logs and moves only their part', function () {
        $task = sharedTask($this, [$this->rani, $this->bayu]);
        $this->travelTo(CarbonImmutable::parse('2026-09-24 02:00:00'));
        $this->actingAs($this->rani)->post(route('tasks.start', $task));
        $this->actingAs($this->bayu)->post(route('tasks.start', $task));
        $this->travelTo(CarbonImmutable::parse('2026-09-24 04:00:00'));

        sendEvidence($this, $this->rani, $task)->assertSessionHasNoErrors();

        $logs = WorkActivityLog::query()->where('task_id', $task->id)->get();
        $audit = AuditLog::query()->where('action', 'task.submitted')->sole();

        expect($logs)->toHaveCount(1)
            ->and($logs[0]->user_id)->toBe($this->rani->id)
            ->and(TaskWorkSession::query()->where('user_id', $this->bayu->id)->whereNull('ended_at')->exists())->toBeTrue()
            ->and(partOf($task, $this->rani))->toBe(PartStatus::Submitted)
            ->and(partOf($task, $this->bayu))->toBe(PartStatus::Open)
            ->and($task->refresh()->status)->toBe(TaskStatus::InProgress)
            ->and($audit->after['person_id'])->toBe($this->rani->id)
            ->and($audit->after['submission_id'])->toBe(latestSubmission($task, $this->rani)->id);
    });
});

describe('reviewing per person', function () {
    it('waits until nobody\'s part is open, then reviews each submission', function () {
        $task = sharedTask($this, [$this->rani, $this->bayu]);
        sendEvidence($this, $this->rani, $task);
        $rani = latestSubmission($task, $this->rani);

        $this->actingAs($this->lead)->post(route('tasks.review', $task), ['decision' => 'approve', 'submission_id' => $rani->id])->assertForbidden();
        $this->actingAs($this->lead)->get(route('projects.mine'))->assertInertia(fn ($page) => $page->has('waiting', 0));

        sendEvidence($this, $this->bayu, $task);
        $bayu = latestSubmission($task, $this->bayu);
        expect($task->refresh()->status)->toBe(TaskStatus::InReview);
        $this->actingAs($this->lead)->get(route('projects.mine'))->assertInertia(fn ($page) => $page->has('waiting', 1)->where('nav_badges.my_tasks', 1));

        $this->actingAs($this->lead)->post(route('tasks.review', $task), ['decision' => 'approve', 'submission_id' => $rani->id])->assertSessionHasNoErrors();

        expect(partOf($task, $this->rani))->toBe(PartStatus::Approved)
            ->and($task->refresh()->status)->toBe(TaskStatus::InReview)
            ->and($task->completed_at)->toBeNull();

        $this->actingAs($this->lead)->post(route('tasks.review', $task), ['decision' => 'changes', 'submission_id' => $bayu->id])->assertSessionHasErrors('note');
        $this->actingAs($this->lead)
            ->post(route('tasks.review', $task), ['decision' => 'changes', 'submission_id' => $bayu->id, 'note' => 'Tangan kiri masih menembus badan.'])
            ->assertSessionHasNoErrors();

        expect(partOf($task, $this->bayu))->toBe(PartStatus::ChangesRequested)
            ->and($task->refresh()->status)->toBe(TaskStatus::ChangesRequested)
            ->and($bayu->refresh()->review_status)->toBe(ReviewStatus::ChangesRequested);

        // The same submission cannot be reviewed twice
        $this->actingAs($this->lead)->post(route('tasks.review', $task), ['decision' => 'approve', 'submission_id' => $bayu->id])->assertForbidden();

        sendEvidence($this, $this->bayu, $task)->assertSessionHasNoErrors();
        $again = latestSubmission($task, $this->bayu);
        $this->actingAs($this->lead)->post(route('tasks.review', $task), ['decision' => 'approve', 'submission_id' => $again->id])->assertSessionHasNoErrors();

        $audit = AuditLog::query()->where('action', 'task.reviewed')->latest('id')->first();

        expect($task->refresh())
            ->status->toBe(TaskStatus::Done)
            ->completed_at->not->toBeNull()
            ->and($audit->after)->toMatchArray(['person_id' => $this->bayu->id, 'submission_id' => $again->id, 'status' => 'done', 'part_status' => 'approved']);
    });

    it('does not hold another part\'s review while one part waits for changes', function () {
        $task = sharedTask($this, [$this->rani, $this->bayu]);
        $task->parts()->where('user_id', $this->rani->id)->update(['part_status' => PartStatus::ChangesRequested->value]);
        sendEvidence($this, $this->bayu, $task);

        $this->actingAs($this->lead)
            ->post(route('tasks.review', $task), ['decision' => 'approve', 'submission_id' => latestSubmission($task, $this->bayu)->id])
            ->assertSessionHasNoErrors();

        expect(partOf($task, $this->bayu))->toBe(PartStatus::Approved)
            ->and($task->refresh()->status)->toBe(TaskStatus::ChangesRequested);
    });

    it('refuses a lead\'s review of their own part unless they oversee all projects', function () {
        $task = sharedTask($this, [$this->lead, $this->rani]);
        sendEvidence($this, $this->lead, $task)->assertSessionHasNoErrors();
        sendEvidence($this, $this->rani, $task)->assertSessionHasNoErrors();

        $this->actingAs($this->lead)->post(route('tasks.review', $task), ['decision' => 'approve', 'submission_id' => latestSubmission($task, $this->lead)->id])->assertForbidden();
        $this->actingAs($this->lead)->post(route('tasks.review', $task), ['decision' => 'approve', 'submission_id' => latestSubmission($task, $this->rani)->id])->assertSessionHasNoErrors();

        $own = sharedTask($this, [$this->manager], extra: ['title' => 'Review animatic']);
        sendEvidence($this, $this->manager, $own)->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('tasks.review', $own), ['decision' => 'approve', 'submission_id' => latestSubmission($own, $this->manager)->id])->assertSessionHasNoErrors();

        expect($own->refresh()->status)->toBe(TaskStatus::Done);
    });

    it('drops the submission of someone taken off the task from review', function () {
        $task = sharedTask($this, [$this->rani, $this->bayu]);
        sendEvidence($this, $this->rani, $task);
        sendEvidence($this, $this->bayu, $task);
        $bayu = latestSubmission($task, $this->bayu);

        $this->actingAs($this->lead)
            ->put(route('tasks.update', $task), ['title' => $task->title, 'priority' => 'normal', 'assignee_ids' => [$this->rani->id]])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->lead)->post(route('tasks.review', $task), ['decision' => 'approve', 'submission_id' => $bayu->id])->assertForbidden();
        $this->actingAs($this->lead)->get(route('tasks.show', $task))
            ->assertInertia(fn ($page) => $page
                ->where('submissions.0.id', $bayu->id)
                ->where('submissions.0.waiting', false)
                ->where('submissions.1.can_review', true)
                ->where('can.review', true));
    });
});

describe('pages', function () {
    it('shows each assignee with their part and their own timer minutes', function () {
        $task = sharedTask($this, [$this->rani, $this->bayu]);
        TaskWorkSession::query()->create(['task_id' => $task->id, 'user_id' => $this->rani->id, 'started_at' => now()->subMinutes(90), 'ended_at' => now()]);
        TaskWorkSession::query()->create(['task_id' => $task->id, 'user_id' => $this->bayu->id, 'started_at' => now()->subMinutes(5)]);

        $this->actingAs($this->rani)->get(route('tasks.show', $task))
            ->assertInertia(fn ($page) => $page
                ->where('task.my_part', 'open')
                ->where('task.logged_minutes', 90)
                ->where('task.assignees.0.id', $this->rani->id)
                ->where('task.assignees.0.part_status', 'open')
                ->where('task.assignees.0.logged_minutes', 90)
                ->where('task.assignees.0.running', false)
                ->where('task.assignees.1.running', true)
                ->where('can.work', true)
                ->where('can.claim', false));
    });

    it('lists shared tasks in Tugas saya with the viewer\'s own part', function () {
        $shared = sharedTask($this, [$this->rani, $this->bayu], extra: ['title' => 'Tugas bersama']);
        sharedTask($this, [$this->bayu], extra: ['title' => 'Tugas Bayu saja']);
        sendEvidence($this, $this->rani, $shared);

        $this->actingAs($this->rani)->get(route('projects.mine'))
            ->assertInertia(fn ($page) => $page
                ->has('my_tasks', 1)
                ->where('my_tasks.0.id', $shared->id)
                ->where('my_tasks.0.my_part', 'submitted')
                ->where('my_tasks.0.status', 'in_progress')
                ->has('my_tasks.0.assignees', 2));

        $this->actingAs($this->bayu)->get(route('projects.mine'))->assertInertia(fn ($page) => $page->has('my_tasks', 2));
    });
});

/**
 * Runs $action with the query log on and returns [position of the task row lock, position of the first write to
 * parts, submissions, or timer sessions]. Null when that query did not run.
 *
 * @return array{0: ?int, 1: ?int}
 */
function lockAndFirstWrite(callable $action): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $action();
    DB::disableQueryLog();

    $queries = collect(DB::getQueryLog())->pluck('query');
    $lock = $queries->search(fn (string $q) => str_starts_with($q, 'select * from `tasks` where `tasks`.`id` = ?') && str_ends_with($q, 'for update'));
    $write = $queries->search(fn (string $q) => preg_match('/^(insert into|update|delete from) `(task_assignees|task_submissions|task_work_sessions)`/', $q) === 1);

    return [$lock === false ? null : $lock, $write === false ? null : $write];
}

describe('changes at the same time', function () {
    it('locks the task row before any part, submission, or timer changes', function () {
        $task = sharedTask($this, []);
        $steps = [
            'claim' => fn () => $this->actingAs($this->bayu)->post(route('tasks.claim', $task))->assertRedirect(),
            'timer' => fn () => $this->actingAs($this->bayu)->post(route('tasks.start', $task))->assertRedirect(),
            'assign' => fn () => $this->actingAs($this->lead)->put(route('tasks.update', $task), ['title' => $task->title, 'priority' => 'normal', 'assignee_ids' => [$this->bayu->id, $this->rani->id]])->assertSessionHasNoErrors(),
            'submit' => fn () => sendEvidence($this, $this->bayu, $task)->assertSessionHasNoErrors(),
            'submit last' => fn () => sendEvidence($this, $this->rani, $task)->assertSessionHasNoErrors(),
            'review' => fn () => $this->actingAs($this->lead)->post(route('tasks.review', $task), ['decision' => 'approve', 'submission_id' => latestSubmission($task, $this->bayu)->id])->assertSessionHasNoErrors(),
        ];

        foreach ($steps as $name => $step) {
            [$lock, $write] = lockAndFirstWrite($step);
            expect($lock)->not->toBeNull($name.': no task lock')
                ->and($write)->not->toBeNull($name.': nothing written')
                ->and($lock)->toBeLessThan($write, $name.': wrote before locking the task');
        }

        $this->actingAs($this->citra)->post(route('projects.tasks.store', [$this->project, $this->sub]), ['title' => 'Blendshape mulut', 'priority' => 'normal']);
        $proposal = Task::query()->where('title', 'Blendshape mulut')->sole();
        [$lock, $write] = lockAndFirstWrite(fn () => $this->actingAs($this->lead)
            ->post(route('tasks.decide', $proposal), ['decision' => 'approve', 'assignee_ids' => [$this->citra->id, $this->rani->id]])
            ->assertSessionHasNoErrors());

        expect($lock)->not->toBeNull()->and($write)->not->toBeNull()->and($lock)->toBeLessThan($write);
    });

    it('checks a decision again under the lock, so a proposal rejected meanwhile is not approved', function () {
        $this->actingAs($this->rani)->post(route('projects.tasks.store', [$this->project, $this->sub]), ['title' => 'Rigging wajah Nara', 'priority' => 'normal']);
        $seenByManager = Task::query()->where('title', 'Rigging wajah Nara')->sole();

        $this->actingAs($this->lead)->post(route('tasks.decide', $seenByManager), ['decision' => 'reject', 'note' => 'Sudah dikerjakan di Episode 2.'])->assertSessionHasNoErrors();

        expect(fn () => app(TaskWorkflow::class)->approveProposal($this->manager, $seenByManager, null, null))->toThrow(HttpException::class)
            ->and($seenByManager->refresh()->status)->toBe(TaskStatus::Rejected);
    });

    it('refuses the second of two claims on the same task', function () {
        $task = sharedTask($this, []);
        $seenByCitra = Task::query()->findOrFail($task->id);

        app(TaskWorkflow::class)->claim($this->bayu, $task);

        expect(fn () => app(TaskWorkflow::class)->claim($this->citra, $seenByCitra))->toThrow(HttpException::class)
            ->and($task->assignees()->pluck('users.id')->all())->toBe([$this->bayu->id]);
    });

    it('refuses the timer and evidence of someone taken off the task after the page loaded', function () {
        $task = sharedTask($this, [$this->rani, $this->bayu]);
        $seenByBayu = Task::query()->findOrFail($task->id);

        $this->actingAs($this->lead)
            ->put(route('tasks.update', $task), ['title' => $task->title, 'priority' => 'normal', 'assignee_ids' => [$this->rani->id]])
            ->assertSessionHasNoErrors();

        expect(fn () => app(TaskTimer::class)->start($this->bayu, $seenByBayu))->toThrow(HttpException::class)
            ->and(fn () => app(TaskWorkflow::class)->submit($this->bayu, $seenByBayu, 'Blocking dan spline selesai.', 'https://drive.example/b', null))->toThrow(HttpException::class)
            ->and(TaskWorkSession::query()->where('user_id', $this->bayu->id)->exists())->toBeFalse()
            ->and(TaskSubmission::query()->where('submitted_by', $this->bayu->id)->exists())->toBeFalse();
    });

    it('refuses to review evidence whose sender was taken off the task during the review', function () {
        $task = sharedTask($this, [$this->rani, $this->bayu]);
        sendEvidence($this, $this->rani, $task);
        sendEvidence($this, $this->bayu, $task);
        $bayu = latestSubmission($task, $this->bayu);
        $seenByLead = Task::query()->findOrFail($task->id);

        $this->actingAs($this->manager)
            ->put(route('tasks.update', $task), ['title' => $task->title, 'priority' => 'normal', 'assignee_ids' => [$this->rani->id]])
            ->assertSessionHasNoErrors();

        expect(fn () => app(TaskWorkflow::class)->approve($this->lead, $seenByLead, $bayu, null))->toThrow(HttpException::class)
            ->and($bayu->refresh()->review_status)->toBe(ReviewStatus::Pending);
    });
});

describe('task page details', function () {
    it('sends full names for the accessible labels of stacked avatars', function () {
        $this->rani->forceFill(['name' => 'Rani Kusuma', 'nickname' => 'Rani'])->save();
        $task = sharedTask($this, [$this->rani, $this->bayu], extra: ['due_date' => now()->toDateString()]);

        $this->actingAs($this->bayu)->get(route('tasks.show', $task))
            ->assertInertia(fn ($page) => $page->where('task.assignees.0.name', 'Rani')->where('task.assignees.0.full_name', 'Rani Kusuma'));
        $this->actingAs($this->manager)->get(route('monitoring.work'))
            ->assertInertia(fn ($page) => $page->where('due.rows.0.assignees.0.full_name', 'Rani Kusuma'));
    });

    it('checks review rights on the task page with the same number of queries however many submissions wait', function () {
        $queriesFor = function (int $people) {
            $team = User::factory()->count($people)->withRole(Role::Employee)->create()->all();
            $task = sharedTask($this, $team);
            foreach ($team as $person) {
                TaskSubmission::query()->create(['task_id' => $task->id, 'submitted_by' => $person->id, 'note' => 'Render pass pertama', 'review_status' => ReviewStatus::Pending]);
            }
            $task->parts()->update(['part_status' => PartStatus::Submitted->value]);
            $task->forceFill(['status' => TaskStatus::InReview])->save();

            // The first visit warms the permission cache
            $this->actingAs($this->lead)->get(route('tasks.show', $task))->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->lead)->get(route('tasks.show', $task))
                ->assertInertia(fn ($page) => $page->where('can.review', true)->has('submissions', $people));
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        expect($queriesFor(2))->toBe($queriesFor(5));
    });
});
