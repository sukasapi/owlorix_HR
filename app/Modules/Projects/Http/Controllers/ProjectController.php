<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\MilestoneKind;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Http\Requests\AssignProjectMemberRequest;
use App\Modules\Projects\Http\Requests\StoreProjectRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectRequest;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\ProjectMilestone;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Services\HourBudget;
use App\Modules\Projects\Services\TaskInbox;
use App\Modules\Projects\Services\TaskPresenter;
use App\Modules\Projects\Services\TaskTimer;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        $canManage = $request->user()->hasPermission(Permission::ManageProjects);

        $projects = Project::query()
            ->withCount('members')
            ->orderByRaw("FIELD(status, 'active', 'planned', 'done')")
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => $this->row($project));

        return Inertia::render('projects/Index', [
            'projects' => $projects,
            'can_manage' => $canManage,
            'can_budget' => HourBudget::canSee($request->user()),
            'statuses' => array_map(fn (ProjectStatus $s) => $s->value, ProjectStatus::cases()),
        ]);
    }

    public function show(Request $request, Project $project, HourBudget $budgets): Response
    {
        Gate::authorize('view', $project);

        $project->load(['members' => fn ($q) => $q->orderBy('name')]);

        $people = $request->user()->hasPermission(Permission::ManageProjects)
            ? User::query()->active()->orderBy('name')->get(['id', 'name', 'username', 'status'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'status' => $user->status->value,
                ])
            : [];

        $memberIds = $project->members->pluck('id')->all();

        $subProjects = SubProject::query()
            ->with('lead')
            ->where('project_id', $project->id)
            ->withCount([
                'tasks as tasks_total' => fn ($q) => $q->where('status', '!=', TaskStatus::Rejected),
                'tasks as tasks_done' => fn ($q) => $q->where('status', TaskStatus::Done),
                'tasks as tasks_waiting' => fn ($q) => $q->whereIn('status', [TaskStatus::Proposed, TaskStatus::InReview]),
            ])
            ->orderByRaw("FIELD(status, 'active', 'planned', 'done')")
            ->orderBy('name')
            ->get()
            ->map(fn (SubProject $sub) => [
                ...TaskPresenter::subProject($sub),
                'tasks_total' => $sub->tasks_total,
                'tasks_done' => $sub->tasks_done,
                'tasks_waiting' => $sub->tasks_waiting,
            ]);

        $today = ProjectMilestone::studioToday();
        $milestones = $project->milestones()->orderBy('due_date')->orderBy('id')->get()
            ->map(fn (ProjectMilestone $m) => TaskPresenter::milestone($m, $today))
            ->values();

        // Budget numbers only reach people with projects.budget; for everyone else the key is absent (docs/14 3.3)
        $budget = HourBudget::canSee($request->user())
            ? ['budget' => HourBudget::view($project->budget_minutes, $budgets->loggedForProject($project->id))]
            : [];

        return Inertia::render('projects/Show', [
            ...$budget,
            'project' => [
                ...$this->row($project),
                'description' => $project->description,
                'members' => $project->members->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'initials' => $user->initials(),
                    'photo_url' => $user->photoUrl(),
                    'status' => $user->status->value,
                    'assigned_at' => $user->pivot->assigned_at,
                ])->values(),
            ],
            'sub_projects' => $subProjects,
            'milestones' => $milestones,
            'milestone_kinds' => array_map(fn (MilestoneKind $k) => $k->value, MilestoneKind::cases()),
            'can_budget' => HourBudget::canSee($request->user()),
            'leads' => $request->user()->hasPermission(Permission::ManageProjects)
                ? User::query()->active()->permission(Permission::ManageProjects->value)->orderBy('name')->get(['id', 'name', 'nickname', 'username'])
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->displayName(), 'username' => $u->username])->values()
                : [],
            'people' => collect($people)->reject(fn ($p) => in_array($p['id'], $memberIds, true))->values(),
            'can_manage' => $request->user()->hasPermission(Permission::ManageProjects),
            'statuses' => array_map(fn (ProjectStatus $s) => $s->value, ProjectStatus::cases()),
            'is_assigned' => in_array($request->user()->id, $memberIds, true),
        ]);
    }

    public function store(StoreProjectRequest $request, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('create', Project::class);

        $project = DB::transaction(function () use ($request, $auditor) {
            $data = $request->validated();
            $project = Project::query()->create([
                'name' => $data['name'],
                'code' => $data['code'] ?: null,
                'status' => $data['status'],
                'description' => $data['description'] ?? null,
                'budget_minutes' => HourBudget::submitted($request->user(), $data) ? HourBudget::minutesFromHours($data['budget_hours']) : null,
            ]);
            $auditor->record('project.created', $project, null, [
                'name' => $project->name,
                'code' => $project->code,
                'status' => $project->status->value,
                'budget_minutes' => $project->budget_minutes,
            ]);

            return $project;
        });

        return redirect()->route('projects.show', $project)
            ->with('status', __('projects::messages.created', ['name' => $project->name]));
    }

    public function update(UpdateProjectRequest $request, Project $project, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('update', $project);

        DB::transaction(function () use ($request, $project, $auditor) {
            $before = [
                'name' => $project->name,
                'code' => $project->code,
                'status' => $project->status->value,
                'description' => $project->description,
                'budget_minutes' => $project->budget_minutes,
            ];
            $data = $request->validated();
            $project->forceFill([
                'name' => $data['name'],
                'code' => $data['code'] ?: null,
                'status' => $data['status'],
                'description' => $data['description'] ?? null,
            ]);

            if (HourBudget::submitted($request->user(), $data)) {
                $project->budget_minutes = HourBudget::minutesFromHours($data['budget_hours']);
            }

            $project->save();

            $after = [
                'name' => $project->name,
                'code' => $project->code,
                'status' => $project->status->value,
                'description' => $project->description,
                'budget_minutes' => $project->budget_minutes,
            ];
            $auditor->record('project.updated', $project, $before, $after);
        });

        return back()->with('status', __('projects::messages.updated', ['name' => $project->name]));
    }

    public function assign(AssignProjectMemberRequest $request, Project $project, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('assign', $project);

        $userId = (int) $request->validated('user_id');
        $person = User::query()->findOrFail($userId);

        if ($person->status !== UserStatus::Active) {
            throw ValidationException::withMessages(['user_id' => __('projects::messages.already_member')]);
        }

        if (ProjectMember::query()->where('project_id', $project->id)->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['user_id' => __('projects::messages.already_member')]);
        }

        DB::transaction(function () use ($request, $project, $person, $auditor) {
            ProjectMember::query()->create([
                'project_id' => $project->id,
                'user_id' => $person->id,
                'assigned_by' => $request->user()->id,
                'assigned_at' => now(),
            ]);
            $auditor->record('project.member_added', $project, null, [
                'user_id' => $person->id,
                'username' => $person->username,
            ]);
        });

        return back()->with('status', __('projects::messages.member_added', ['name' => $person->name]));
    }

    public function unassign(Request $request, Project $project, User $user, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('assign', $project);

        $member = ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        DB::transaction(function () use ($member, $project, $user, $auditor) {
            $member->delete();
            $auditor->record('project.member_removed', $project, [
                'user_id' => $user->id,
                'username' => $user->username,
            ], null);
        });

        return back()->with('status', __('projects::messages.member_removed', ['name' => $user->name]));
    }

    public function mine(Request $request, TaskInbox $inbox, TaskTimer $timer): Response
    {
        Gate::authorize('viewAny', Project::class);

        $user = $request->user();
        $withPlace = ['project', 'subProject', 'stage', 'assignees', 'creator'];

        // Every open task the person is on, shared ones included; their own part decides the order (docs/15)
        $myTasks = Task::query()
            ->select('tasks.*')
            ->join('task_assignees as mine', fn ($j) => $j->on('mine.task_id', '=', 'tasks.id')->where('mine.user_id', '=', $user->id))
            ->withLoggedMinutes()
            ->with($withPlace)
            ->whereIn('tasks.status', [TaskStatus::ChangesRequested, TaskStatus::InProgress, TaskStatus::Todo, TaskStatus::InReview])
            ->orderByRaw("FIELD(mine.part_status, 'changes_requested', 'open', 'submitted', 'approved')")
            ->orderByRaw("FIELD(tasks.status, 'changes_requested', 'in_progress', 'todo', 'in_review')")
            ->orderByRaw('tasks.due_date is null, tasks.due_date')
            ->limit(100)
            ->get()
            ->map(fn (Task $t) => TaskPresenter::rowWithPlace($t, $user))
            ->values();

        $waiting = ($inbox->waitingQuery($user)?->with($withPlace)->withLoggedMinutes()->orderBy('updated_at')->limit(100)->get() ?? collect())
            ->map(fn (Task $t) => TaskPresenter::rowWithPlace($t, $user))
            ->values();

        $proposals = Task::query()
            ->with($withPlace)
            ->where('created_by', $user->id)
            ->where(fn ($q) => $q->where('status', TaskStatus::Proposed)
                ->orWhere(fn ($r) => $r->where('status', TaskStatus::Rejected)->where('decided_at', '>=', now()->subDays(30))))
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (Task $t) => TaskPresenter::rowWithPlace($t))
            ->values();

        $assignments = ProjectMember::query()
            ->with('project')
            ->where('user_id', $user->id)
            ->orderByDesc('assigned_at')
            ->get()
            ->filter(fn (ProjectMember $m) => $m->project !== null)
            ->map(fn (ProjectMember $m) => [
                'assigned_at' => $m->assigned_at?->toIso8601String(),
                'project' => $this->row($m->project),
            ])
            ->values();

        return Inertia::render('projects/Mine', [
            'assignments' => $assignments,
            'my_tasks' => $myTasks,
            'waiting' => $waiting,
            'proposals' => $proposals,
            'running' => TaskPresenter::timer($timer->running($user)?->load('task')),
        ]);
    }

    /** @return array{id: int, name: string, code: ?string, status: string, members_count: int} */
    private function row(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'code' => $project->code,
            'status' => $project->status->value,
            'members_count' => $project->members_count ?? $project->members()->count(),
        ];
    }
}
