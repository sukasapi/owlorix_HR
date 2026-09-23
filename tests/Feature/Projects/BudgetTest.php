<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\WorkActivityLog;
use App\Modules\Projects\Services\HourBudget;
use App\Modules\Shared\Audit\AuditLog;

// docs/14 section 3.3: hour budgets, set and seen only by projects.budget holders (PM, PD, Superadmin).

beforeEach(function () {
    $this->project = Project::query()->create(['name' => 'Film Pendek', 'code' => 'FP', 'status' => ProjectStatus::Active, 'budget_minutes' => 3600]);
    $this->sub = $this->project->subProjects()->create(['name' => 'Episode 1', 'status' => ProjectStatus::Active, 'budget_minutes' => 600]);
});

function budgetLog(User $user, Project $project, int $minutes, ?Task $task = null, string $start = '2026-09-21 02:00:00'): WorkActivityLog
{
    $from = \Carbon\CarbonImmutable::parse($start, 'UTC');

    return WorkActivityLog::query()->create([
        'user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task?->id,
        'description' => 'Blocking shot hero walk cycle', 'started_at' => $from, 'ended_at' => $from->addMinutes($minutes),
        'evidence_url' => 'https://drive.example/shot',
    ]);
}

function budgetTask(Project $project, SubProject $sub, User $by): Task
{
    return Task::query()->create([
        'project_id' => $project->id, 'sub_project_id' => $sub->id, 'title' => 'Rig Nara',
        'status' => TaskStatus::Todo, 'priority' => 'normal', 'created_by' => $by->id,
    ]);
}

it('sends budget numbers only to budget holders', function () {
    foreach ([Role::Employee, Role::TeamLead] as $role) {
        $user = userWithRole($role);

        $this->actingAs($user)->get(route('projects.show', $this->project))
            ->assertInertia(fn ($page) => $page->missing('budget')->missing('project.budget_minutes')->where('can_budget', false));
        $this->actingAs($user)->get(route('projects.sub.show', [$this->project, $this->sub]))
            ->assertInertia(fn ($page) => $page->missing('budget')->missing('sub_project.budget_minutes')->where('can.budget', false));
        $this->actingAs($user)->get(route('projects.index'))
            ->assertInertia(fn ($page) => $page->where('can_budget', false)->missing('projects.0.budget_minutes'));

        expect(json_encode($this->actingAs($user)->get(route('projects.show', $this->project))->original->getData()['page']['props']))
            ->not->toContain('3600');
    }

    foreach ([Role::ProjectManager, Role::ProjectDirector, Role::Superadmin] as $role) {
        $user = userWithRole($role);

        $this->actingAs($user)->get(route('projects.show', $this->project))
            ->assertInertia(fn ($page) => $page->where('budget', ['minutes' => 3600, 'logged_minutes' => 0])->where('can_budget', true));
        $this->actingAs($user)->get(route('projects.sub.show', [$this->project, $this->sub]))
            ->assertInertia(fn ($page) => $page->where('budget', ['minutes' => 600, 'logged_minutes' => 0])->where('can.budget', true));
    }
});

it('lets budget holders set budgets in hours and audits the change', function () {
    $manager = userWithRole(Role::ProjectManager);

    $this->actingAs($manager)->put(route('projects.update', $this->project), [
        'name' => 'Film Pendek', 'code' => 'FP', 'status' => 'active', 'description' => null, 'budget_hours' => '12.5',
    ])->assertSessionHasNoErrors();
    expect($this->project->fresh()->budget_minutes)->toBe(750);

    $log = AuditLog::query()->where('action', 'project.updated')->latest('id')->firstOrFail();
    expect($log->before['budget_minutes'])->toBe(3600)->and($log->after['budget_minutes'])->toBe(750);

    $this->actingAs($manager)->post(route('projects.store'), ['name' => 'Iklan', 'status' => 'active', 'code' => null, 'budget_hours' => 40])
        ->assertSessionHasNoErrors();
    expect(Project::query()->where('name', 'Iklan')->value('budget_minutes'))->toBe(2400);

    $this->actingAs($manager)->put(route('projects.sub.update', [$this->project, $this->sub]), ['name' => 'Episode 1', 'status' => 'active', 'budget_hours' => null])
        ->assertSessionHasNoErrors();
    expect($this->sub->fresh()->budget_minutes)->toBeNull();
    expect(AuditLog::query()->where('action', 'sub_project.updated')->latest('id')->first())
        ->before->toMatchArray(['budget_minutes' => 600])
        ->after->toMatchArray(['budget_minutes' => null]);

    $this->actingAs($manager)->post(route('projects.sub.store', $this->project), ['name' => 'Episode 2', 'status' => 'active', 'budget_hours' => 7.5])
        ->assertSessionHasNoErrors();
    expect(SubProject::query()->where('name', 'Episode 2')->value('budget_minutes'))->toBe(450);

    $this->actingAs($manager)->put(route('projects.update', $this->project), ['name' => 'Film Pendek', 'status' => 'active', 'budget_hours' => 'banyak'])
        ->assertSessionHasErrors('budget_hours');
    $this->actingAs($manager)->put(route('projects.update', $this->project), ['name' => 'Film Pendek', 'status' => 'active', 'budget_hours' => 0])
        ->assertSessionHasErrors('budget_hours');
});

it('refuses a budget from someone without projects.budget and keeps the stored one on their edits', function () {
    $lead = userWithRole(Role::TeamLead);

    $this->actingAs($lead)->put(route('projects.update', $this->project), ['name' => 'Film Pendek 2', 'status' => 'active', 'budget_hours' => 1])
        ->assertSessionHasErrors('budget_hours');
    $this->actingAs($lead)->post(route('projects.store'), ['name' => 'Iklan', 'status' => 'active', 'budget_hours' => 10])
        ->assertSessionHasErrors('budget_hours');
    $this->actingAs($lead)->post(route('projects.sub.store', $this->project), ['name' => 'Episode 2', 'status' => 'active', 'budget_hours' => 10])
        ->assertSessionHasErrors('budget_hours');

    // Their normal edits do not touch the budget
    $this->actingAs($lead)->put(route('projects.update', $this->project), ['name' => 'Film Pendek 2', 'code' => 'FP', 'status' => 'active'])
        ->assertSessionHasNoErrors();
    $this->actingAs($lead)->put(route('projects.sub.update', [$this->project, $this->sub]), ['name' => 'Episode 1b', 'status' => 'active', 'budget_hours' => null])
        ->assertSessionHasNoErrors();

    expect($this->project->fresh())->name->toBe('Film Pendek 2')->budget_minutes->toBe(3600)
        ->and($this->sub->fresh())->name->toBe('Episode 1b')->budget_minutes->toBe(600);
});

it('adds up logged hours per project and per sub project without deleted logs', function () {
    $person = userWithRole(Role::Employee);
    $otherSub = $this->project->subProjects()->create(['name' => 'Aset', 'status' => ProjectStatus::Active]);
    $other = Project::query()->create(['name' => 'Iklan', 'status' => ProjectStatus::Active]);
    $task = budgetTask($this->project, $this->sub, $person);
    $otherTask = budgetTask($this->project, $otherSub, $person);

    budgetLog($person, $this->project, 90, $task);
    budgetLog($person, $this->project, 45, $task, '2026-09-21 05:00:00');
    budgetLog($person, $this->project, 30, $otherTask);
    budgetLog($person, $this->project, 20);
    budgetLog($person, $this->project, 60, $task)->delete();
    budgetLog($person, $other, 500);

    // A task deleted later still counts toward its sub project
    $task->delete();

    $budgets = app(HourBudget::class);
    expect($budgets->loggedForProject($this->project->id))->toBe(185)
        ->and($budgets->loggedForSubProject($this->sub->id))->toBe(135)
        ->and($budgets->loggedBySubProject([$this->sub->id, $otherSub->id]))->toBe([$this->sub->id => 135, $otherSub->id => 30])
        ->and($budgets->loggedByProject([$this->project->id, $other->id, 999999]))->toBe([$this->project->id => 185, $other->id => 500, 999999 => 0]);

    $this->actingAs(userWithRole(Role::ProjectDirector))->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->where('budget.logged_minutes', 185));
    $this->actingAs(userWithRole(Role::ProjectDirector))->get(route('projects.sub.show', [$this->project, $this->sub]))
        ->assertInertia(fn ($page) => $page->where('budget.logged_minutes', 135));
});
