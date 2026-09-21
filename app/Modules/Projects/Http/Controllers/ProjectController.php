<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Http\Requests\AssignProjectMemberRequest;
use App\Modules\Projects\Http\Requests\StoreProjectRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectRequest;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
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
            'statuses' => array_map(fn (ProjectStatus $s) => $s->value, ProjectStatus::cases()),
        ]);
    }

    public function show(Request $request, Project $project): Response
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

        return Inertia::render('projects/Show', [
            'project' => [
                ...$this->row($project),
                'description' => $project->description,
                'members' => $project->members->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'initials' => $user->initials(),
                    'status' => $user->status->value,
                    'assigned_at' => $user->pivot->assigned_at,
                ])->values(),
            ],
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
            ]);
            $auditor->record('project.created', $project, null, [
                'name' => $project->name,
                'code' => $project->code,
                'status' => $project->status->value,
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
            ];
            $data = $request->validated();
            $project->forceFill([
                'name' => $data['name'],
                'code' => $data['code'] ?: null,
                'status' => $data['status'],
                'description' => $data['description'] ?? null,
            ])->save();

            $after = [
                'name' => $project->name,
                'code' => $project->code,
                'status' => $project->status->value,
                'description' => $project->description,
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

    public function mine(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        $assignments = ProjectMember::query()
            ->with('project')
            ->where('user_id', $request->user()->id)
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
