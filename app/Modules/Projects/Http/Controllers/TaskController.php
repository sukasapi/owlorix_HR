<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Http\Requests\DecideTaskRequest;
use App\Modules\Projects\Http\Requests\ReviewTaskRequest;
use App\Modules\Projects\Http\Requests\SubmitTaskRequest;
use App\Modules\Projects\Http\Requests\TaskRequest;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskSubmission;
use App\Modules\Projects\Models\TaskWorkSession;
use App\Modules\Projects\Services\TaskPresenter;
use App\Modules\Projects\Services\TaskTimer;
use App\Modules\Projects\Services\TaskWorkflow;
use App\Modules\Shared\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tasks in a sub project (docs/13). A lead adds tasks straight to the list; a member proposes and the lead approves
 * or rejects. The assignee runs the timer and sends evidence; the lead reviews it.
 */
class TaskController extends Controller
{
    public function __construct(
        private readonly TaskWorkflow $workflow,
        private readonly TaskTimer $timer,
    ) {}

    public function show(Request $request, Task $task): Response
    {
        Gate::authorize('view', $task);

        $user = $request->user();
        $task->load(['project', 'subProject.lead', 'assignee', 'creator', 'decider']);
        $isLead = $task->subProject !== null && Gate::allows('lead', $task->subProject);

        $sessions = TaskWorkSession::query()->with('user')->where('task_id', $task->id)->orderByDesc('started_at')->limit(200)->get();
        $unlogged = $sessions->filter(fn (TaskWorkSession $s) => $s->user_id === $user->id && $s->ended_at !== null && $s->work_activity_log_id === null && $s->minutes() >= TaskWorkflow::MIN_LOG_MINUTES);

        return Inertia::render('projects/Task', [
            'task' => [
                ...TaskPresenter::row($task),
                'description' => $task->description,
                'decider' => TaskPresenter::person($task->decider),
                'decided_at' => $task->decided_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'created_at' => $task->created_at?->toIso8601String(),
                'logged_minutes' => $sessions->sum(fn (TaskWorkSession $s) => $s->minutes()),
                'project' => ['id' => $task->project->id, 'name' => $task->project->name, 'code' => $task->project->code],
                'sub_project' => TaskPresenter::subProject($task->subProject),
            ],
            'sessions' => $sessions->map(fn (TaskWorkSession $s) => TaskPresenter::session($s))->values(),
            'submissions' => $task->submissions()->with(['submitter', 'reviewer'])->orderByDesc('id')->get()
                ->map(fn (TaskSubmission $s) => TaskPresenter::submission($s))->values(),
            'running' => TaskPresenter::timer($this->timer->running($user)?->load('task')),
            'unlogged' => ['count' => $unlogged->count(), 'minutes' => $unlogged->sum(fn (TaskWorkSession $s) => $s->minutes())],
            'can' => [
                'update' => Gate::allows('update', $task),
                'delete' => Gate::allows('delete', $task),
                'decide' => Gate::allows('decide', $task),
                'claim' => Gate::allows('claim', $task),
                'work' => Gate::allows('work', $task),
                'review' => Gate::allows('review', $task),
                'lead' => $isLead,
            ],
            'people' => $isLead ? User::query()->active()->orderBy('name')->get(['id', 'name', 'nickname', 'username'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->displayName(), 'username' => $u->username])->values() : [],
            'priorities' => array_map(fn (TaskPriority $p) => $p->value, TaskPriority::cases()),
            'limits' => ['file_max_kb' => SubmitTaskRequest::FILE_MAX_KB, 'file_types' => SubmitTaskRequest::FILE_TYPES],
        ]);
    }

    public function store(TaskRequest $request, Project $project, SubProject $subProject, Auditor $auditor): RedirectResponse
    {
        $user = $request->user();
        $direct = Gate::allows('createTask', $subProject);

        if (! $direct) {
            Gate::authorize('proposeTask', $subProject);
        }

        $data = $request->validated();

        $task = DB::transaction(function () use ($user, $project, $subProject, $data, $direct, $auditor) {
            $task = Task::query()->create([
                'project_id' => $project->id,
                'sub_project_id' => $subProject->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $direct ? TaskStatus::Todo : TaskStatus::Proposed,
                'priority' => $data['priority'],
                // A proposal is work the proposer plans to do; a lead picks anyone or leaves it open
                'assignee_id' => $direct ? ($data['assignee_id'] ?? null) : $user->id,
                'created_by' => $user->id,
                'due_date' => $data['due_date'] ?? null,
                'estimate_minutes' => $this->minutes($data['estimate_hours'] ?? null),
                'evidence_required' => $direct ? (bool) ($data['evidence_required'] ?? true) : true,
            ]);
            $auditor->record($direct ? 'task.created' : 'task.proposed', $task, null, [
                'sub_project_id' => $subProject->id,
                'title' => $task->title,
                'status' => $task->status->value,
                'assignee_id' => $task->assignee_id,
            ]);

            return $task;
        });

        return back()->with('status', __($direct ? 'projects::messages.task_created' : 'projects::messages.task_proposed', ['title' => $task->title]));
    }

    public function update(TaskRequest $request, Task $task, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('update', $task);

        $data = $request->validated();
        $isLead = Gate::allows('lead', $task->subProject);

        DB::transaction(function () use ($task, $data, $isLead, $auditor) {
            $fields = ['title', 'description', 'priority', 'due_date', 'estimate_minutes', 'assignee_id', 'evidence_required'];
            $before = $this->snapshot($task, $fields);

            $task->forceFill([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'priority' => $data['priority'],
                'due_date' => $data['due_date'] ?? null,
                'estimate_minutes' => $this->minutes($data['estimate_hours'] ?? null),
            ]);

            if ($isLead) {
                $task->forceFill([
                    'assignee_id' => $data['assignee_id'] ?? null,
                    'evidence_required' => (bool) ($data['evidence_required'] ?? $task->evidence_required),
                ]);
            }

            $task->save();
            $auditor->record('task.updated', $task, $before, $this->snapshot($task, $fields));
        });

        return back()->with('status', __('projects::messages.task_updated'));
    }

    public function destroy(Request $request, Task $task, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('delete', $task);

        $place = [$task->project_id, $task->sub_project_id];

        DB::transaction(function () use ($task, $auditor) {
            TaskWorkSession::query()->where('task_id', $task->id)->whereNull('ended_at')->update(['ended_at' => now()]);
            $auditor->record('task.deleted', $task, ['title' => $task->title, 'status' => $task->status->value], null);
            $task->delete();
        });

        return redirect()->route('projects.sub.show', $place)->with('status', __('projects::messages.task_deleted'));
    }

    public function decide(DecideTaskRequest $request, Task $task): RedirectResponse
    {
        Gate::authorize('decide', $task);

        $data = $request->validated();
        $note = isset($data['note']) ? trim($data['note']) : null;

        if ($data['decision'] === 'approve') {
            $this->workflow->approveProposal($request->user(), $task, $data['assignee_id'] ?? null, $note ?: null);

            return back()->with('status', __('projects::messages.proposal_approved'));
        }

        $this->workflow->rejectProposal($request->user(), $task, (string) $note);

        return back()->with('status', __('projects::messages.proposal_rejected'));
    }

    public function claim(Request $request, Task $task): RedirectResponse
    {
        Gate::authorize('claim', $task);

        $this->workflow->claim($request->user(), $task);

        return back()->with('status', __('projects::messages.task_claimed'));
    }

    public function start(Request $request, Task $task): RedirectResponse
    {
        Gate::authorize('work', $task);

        $this->timer->start($request->user(), $task);

        return back();
    }

    public function stop(Request $request): RedirectResponse
    {
        $this->timer->stop($request->user());

        return back();
    }

    public function submit(SubmitTaskRequest $request, Task $task): RedirectResponse
    {
        Gate::authorize('work', $task);

        $data = $request->validated();
        $zone = config('owlorix.display_timezone');
        $logs = $this->workflow->submit(
            $request->user(),
            $task,
            trim($data['note']),
            $data['evidence_url'] ?? null,
            $request->file('evidence_file'),
            isset($data['worked_from']) ? CarbonImmutable::parse($data['worked_from'], $zone) : null,
            isset($data['worked_until']) ? CarbonImmutable::parse($data['worked_until'], $zone) : null,
        );

        return back()->with('status', trans_choice('projects::messages.task_submitted', $logs, ['count' => $logs]));
    }

    public function review(ReviewTaskRequest $request, Task $task): RedirectResponse
    {
        Gate::authorize('review', $task);

        $data = $request->validated();
        $note = isset($data['note']) ? trim($data['note']) : null;

        if ($data['decision'] === 'approve') {
            $this->workflow->approve($request->user(), $task, $note ?: null);

            return back()->with('status', __('projects::messages.review_approved'));
        }

        $this->workflow->requestChanges($request->user(), $task, (string) $note);

        return back()->with('status', __('projects::messages.review_changes'));
    }

    /** Evidence file: anyone who can see the task. Private disk, sent as a download. */
    public function evidence(Request $request, TaskSubmission $submission): StreamedResponse
    {
        $task = Task::withTrashed()->findOrFail($submission->task_id);
        Gate::authorize('view', $task);
        abort_if($submission->file_path === null || ! Storage::disk(TaskWorkflow::EVIDENCE_DISK)->exists($submission->file_path), 404);

        return Storage::disk(TaskWorkflow::EVIDENCE_DISK)->download($submission->file_path, $submission->file_name ?: 'bukti', [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function minutes(mixed $hours): ?int
    {
        return $hours === null || $hours === '' ? null : (int) round(((float) $hours) * 60);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function snapshot(Task $task, array $fields): array
    {
        return collect($fields)->mapWithKeys(fn (string $f) => [$f => match (true) {
            $task->{$f} instanceof \BackedEnum => $task->{$f}->value,
            $task->{$f} instanceof \DateTimeInterface => $task->{$f}->format('Y-m-d'),
            default => $task->{$f},
        }])->all();
    }
}
