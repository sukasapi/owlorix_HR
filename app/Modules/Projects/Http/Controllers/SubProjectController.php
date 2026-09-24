<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Http\Requests\SubProjectRequest;
use App\Modules\Projects\Http\Requests\TaskRequest;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Services\HourBudget;
use App\Modules\Projects\Services\TaskPresenter;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Sub projects inside a project, and the task list of one sub project (docs/13). */
class SubProjectController extends Controller
{
    public function show(Request $request, Project $project, SubProject $subProject, HourBudget $budgets): Response
    {
        Gate::authorize('view', $project);

        $user = $request->user();
        $subProject->load('lead');
        $isLead = Gate::allows('lead', $subProject);

        $tasks = Task::query()
            ->withLoggedMinutes()
            ->with(['stage', 'assignees', 'creator'])
            ->where('sub_project_id', $subProject->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (Task $task) => TaskPresenter::row($task, $user));

        // Budget numbers only reach people with projects.budget; for everyone else the key is absent (docs/14 3.3)
        $budget = HourBudget::canSee($user)
            ? ['budget' => HourBudget::view($subProject->budget_minutes, $budgets->loggedForSubProject($subProject->id))]
            : [];

        return Inertia::render('projects/SubProject', [
            ...$budget,
            'project' => ['id' => $project->id, 'name' => $project->name, 'code' => $project->code, 'status' => $project->status->value],
            'sub_project' => TaskPresenter::subProject($subProject),
            'tasks' => $tasks,
            'can' => [
                'manage' => Gate::allows('update', $subProject),
                'lead' => $isLead,
                'reorder' => $isLead,
                'create_task' => Gate::allows('createTask', $subProject),
                'propose_task' => Gate::allows('proposeTask', $subProject),
                'budget' => HourBudget::canSee($user),
            ],
            'stages' => TaskPresenter::stageOptions(),
            'people' => $isLead ? TaskPresenter::assigneeOptions($project->id) : [],
            'max_assignees' => TaskRequest::MAX_ASSIGNEES,
            'leads' => Gate::allows('update', $subProject) ? $this->leadOptions() : [],
            'statuses' => array_map(fn (ProjectStatus $s) => $s->value, ProjectStatus::cases()),
            'task_statuses' => array_map(fn (TaskStatus $s) => $s->value, TaskStatus::cases()),
            'priorities' => array_map(fn (TaskPriority $p) => $p->value, TaskPriority::cases()),
            'viewer_id' => $user->id,
        ]);
    }

    public function store(SubProjectRequest $request, Project $project, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('create', [SubProject::class, $project]);

        $data = $request->validated();
        $this->ensureLead($data['lead_user_id'] ?? null);

        $budget = HourBudget::submitted($request->user(), $data) ? HourBudget::minutesFromHours($data['budget_hours']) : null;

        $subProject = DB::transaction(function () use ($project, $data, $budget, $auditor) {
            $subProject = $project->subProjects()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
                'lead_user_id' => $data['lead_user_id'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'budget_minutes' => $budget,
            ]);
            $auditor->record('sub_project.created', $subProject, null, [
                'project_id' => $project->id,
                'name' => $subProject->name,
                'lead_user_id' => $subProject->lead_user_id,
                'budget_minutes' => $subProject->budget_minutes,
            ]);

            return $subProject;
        });

        return redirect()->route('projects.sub.show', [$project, $subProject])
            ->with('status', __('projects::messages.sub_created', ['name' => $subProject->name]));
    }

    public function update(SubProjectRequest $request, Project $project, SubProject $subProject, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('update', $subProject);

        $data = $request->validated();
        $this->ensureLead($data['lead_user_id'] ?? null);

        $setBudget = HourBudget::submitted($request->user(), $data);

        DB::transaction(function () use ($subProject, $data, $setBudget, $auditor) {
            $before = $subProject->only(['name', 'description', 'status', 'lead_user_id', 'due_date', 'budget_minutes']);
            $subProject->forceFill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
                'lead_user_id' => $data['lead_user_id'] ?? null,
                'due_date' => $data['due_date'] ?? null,
            ]);

            if ($setBudget) {
                $subProject->budget_minutes = HourBudget::minutesFromHours($data['budget_hours']);
            }

            $subProject->save();
            $auditor->record('sub_project.updated', $subProject, [
                ...$before,
                'status' => $before['status']?->value,
                'due_date' => $before['due_date']?->format('Y-m-d'),
            ], [
                'name' => $subProject->name,
                'description' => $subProject->description,
                'status' => $subProject->status->value,
                'lead_user_id' => $subProject->lead_user_id,
                'due_date' => $subProject->due_date?->format('Y-m-d'),
                'budget_minutes' => $subProject->budget_minutes,
            ]);
        });

        return back()->with('status', __('projects::messages.sub_updated', ['name' => $subProject->name]));
    }

    /** A lead decides proposals and reviews evidence, so it must be someone who manages projects. */
    private function ensureLead(?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $lead = User::query()->find($userId);

        if ($lead === null || ! $lead->hasPermission(Permission::ManageProjects)) {
            throw ValidationException::withMessages(['lead_user_id' => __('projects::messages.lead_needs_manage')]);
        }
    }

    /** @return list<array{id: int, name: string, username: string}> */
    private function leadOptions(): array
    {
        return User::query()->active()->permission(Permission::ManageProjects->value)->orderBy('name')->get(['id', 'name', 'nickname', 'username'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->displayName(), 'username' => $u->username])
            ->values()->all();
    }
}
