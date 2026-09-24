<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\Task;
use App\Modules\Shared\Audit\AuditLog;

// docs/13 4.7: the lead sets the order of the tasks in a sub project, per status group.

beforeEach(function () {
    $this->lead = userWithRole(Role::TeamLead);
    $this->otherLead = userWithRole(Role::TeamLead);
    $this->manager = userWithRole(Role::ProjectManager);
    $this->member = userWithRole(Role::Employee);

    $this->project = Project::query()->create(['name' => 'Film Pendek', 'code' => 'FP', 'status' => ProjectStatus::Active]);
    ProjectMember::query()->create(['project_id' => $this->project->id, 'user_id' => $this->member->id, 'assigned_at' => now()]);
    $this->sub = $this->project->subProjects()->create(['name' => 'Episode 1', 'status' => ProjectStatus::Active, 'lead_user_id' => $this->lead->id]);
});

function orderedTask(SubProject $sub, string $title, int $position, TaskStatus $status = TaskStatus::Todo): Task
{
    return Task::query()->create([
        'project_id' => $sub->project_id,
        'sub_project_id' => $sub->id,
        'title' => $title,
        'status' => $status,
        'priority' => 'normal',
        'position' => $position,
        'created_by' => $sub->lead_user_id,
    ]);
}

/** @return list<string> */
function titlesInOrder(SubProject $sub): array
{
    return Task::query()->where('sub_project_id', $sub->id)->orderBy('position')->orderBy('id')->pluck('title')->all();
}

it('moves a task inside its status group and keeps other groups in place', function () {
    $a = orderedTask($this->sub, 'A', 1);
    orderedTask($this->sub, 'Review', 2, TaskStatus::InReview);
    orderedTask($this->sub, 'B', 3);
    $c = orderedTask($this->sub, 'C', 4);

    $this->actingAs($this->lead)->post(route('tasks.move', $c), ['index' => 0])->assertRedirect();
    expect(titlesInOrder($this->sub))->toBe(['C', 'A', 'Review', 'B']);

    $this->actingAs($this->lead)->post(route('tasks.move', $c), ['index' => 99])->assertRedirect();
    expect(titlesInOrder($this->sub))->toBe(['A', 'Review', 'B', 'C']);

    $this->actingAs($this->lead)->post(route('tasks.move', $a), ['index' => 1])->assertRedirect();
    expect(titlesInOrder($this->sub))->toBe(['Review', 'B', 'A', 'C'])
        ->and(Task::query()->where('sub_project_id', $this->sub->id)->orderBy('position')->pluck('position')->all())->toBe([1, 2, 3, 4])
        ->and(AuditLog::query()->where('action', 'task.reordered')->count())->toBe(3);
});

it('does nothing when the task stays where it is', function () {
    $a = orderedTask($this->sub, 'A', 1);
    orderedTask($this->sub, 'B', 2);

    $this->actingAs($this->lead)->post(route('tasks.move', $a), ['index' => 0])->assertRedirect();

    expect(titlesInOrder($this->sub))->toBe(['A', 'B'])
        ->and(AuditLog::query()->where('action', 'task.reordered')->exists())->toBeFalse();
});

it('lets the lead and overseers reorder, and nobody else', function () {
    orderedTask($this->sub, 'A', 1);
    $b = orderedTask($this->sub, 'B', 2);

    $this->actingAs($this->member)->post(route('tasks.move', $b), ['index' => 0])->assertForbidden();
    $this->actingAs($this->otherLead)->post(route('tasks.move', $b), ['index' => 0])->assertForbidden();
    expect(titlesInOrder($this->sub))->toBe(['A', 'B']);

    $this->actingAs($this->manager)->post(route('tasks.move', $b), ['index' => 0])->assertRedirect();
    expect(titlesInOrder($this->sub))->toBe(['B', 'A']);
});

it('needs a whole number index', function () {
    $a = orderedTask($this->sub, 'A', 1);

    $this->actingAs($this->lead)->post(route('tasks.move', $a), ['index' => -1])->assertSessionHasErrors('index');
    $this->actingAs($this->lead)->post(route('tasks.move', $a), [])->assertSessionHasErrors('index');
});

it('puts new tasks at the bottom and lists tasks in the set order', function () {
    orderedTask($this->sub, 'Lama', 1);
    orderedTask($this->sub, 'Mendesak', 2)->forceFill(['priority' => 'urgent'])->save();

    $this->actingAs($this->lead)
        ->post(route('projects.tasks.store', [$this->project, $this->sub]), ['title' => 'Baru', 'priority' => 'urgent'])
        ->assertSessionHasNoErrors();

    expect(Task::query()->where('title', 'Baru')->value('position'))->toBe(3);

    $this->actingAs($this->lead)->get(route('projects.sub.show', [$this->project, $this->sub]))
        ->assertInertia(fn ($page) => $page->where('tasks.0.title', 'Lama')->where('tasks.1.title', 'Mendesak')->where('tasks.2.title', 'Baru')->where('can.reorder', true));

    $this->actingAs($this->member)->get(route('projects.sub.show', [$this->project, $this->sub]))
        ->assertInertia(fn ($page) => $page->where('can.reorder', false));
});
