<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
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
use App\Modules\Projects\Policies\TaskPolicy;
use App\Modules\Projects\Services\TaskParts;
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
 * Tasks in a sub project (docs/13, docs/15). A lead adds tasks straight to the list; a member proposes and the lead
 * approves or rejects. Each assignee runs their own timer and sends their own evidence; the lead reviews each
 * submission once nobody's part is still open.
 */
class TaskController extends Controller
{
    public function __construct(
        private readonly TaskWorkflow $workflow,
        private readonly TaskTimer $timer,
        private readonly TaskParts $parts,
    ) {}

    public function show(Request $request, Task $task): Response
    {
        Gate::authorize('view', $task);

        $user = $request->user();
        $task->load(['project', 'subProject.lead', 'stage', 'assignees', 'creator', 'decider']);
        $isLead = $task->subProject !== null && Gate::allows('lead', $task->subProject);

        $sessions = TaskWorkSession::query()->with('user')->where('task_id', $task->id)->orderByDesc('started_at')->limit(200)->get();
        $unlogged = TaskWorkSession::query()
            ->where('task_id', $task->id)
            ->where('user_id', $user->id)
            ->whereNotNull('ended_at')
            ->whereNull('work_activity_log_id')
            ->get()
            ->filter(fn (TaskWorkSession $s) => $s->minutes() >= TaskWorkflow::MIN_LOG_MINUTES);

        // Timer minutes per person over every closed session, and who has a timer running here now
        $minutes = TaskWorkSession::query()
            ->where('task_id', $task->id)
            ->whereNotNull('ended_at')
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(timestampdiff(minute, started_at, ended_at)) as minutes')
            ->pluck('minutes', 'user_id')
            ->map(fn ($m) => (int) $m);
        $runningIds = TaskWorkSession::query()->where('task_id', $task->id)->whereNull('ended_at')->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        // TaskPolicy::review, split so the task-wide part runs once instead of once per submission
        $waitingIds = $task->waitingSubmissions()->pluck('task_submissions.id')->map(fn ($id) => (int) $id)->all();
        $reviewOpen = $waitingIds !== [] && Gate::allows('reviewOpen', $task);
        $submissions = $task->submissions()->with(['submitter', 'reviewer'])->orderByDesc('id')->get()
            ->map(function (TaskSubmission $s) use ($user, $waitingIds, $reviewOpen) {
                $waiting = in_array($s->id, $waitingIds, true);

                return [
                    ...TaskPresenter::submission($s),
                    // Pending but not waiting: the sender was taken off the task (or sent again later)
                    'waiting' => $waiting,
                    'can_review' => $waiting && $reviewOpen && TaskPolicy::mayReviewSender($user, $s),
                ];
            })
            ->values();

        return Inertia::render('projects/Task', [
            'task' => [
                ...TaskPresenter::row($task, $user),
                'assignees' => collect(TaskPresenter::assignees($task))->map(fn (array $a) => [
                    ...$a,
                    'logged_minutes' => $minutes[$a['id']] ?? 0,
                    'running' => in_array($a['id'], $runningIds, true),
                ])->all(),
                'description' => $task->description,
                'decider' => TaskPresenter::person($task->decider),
                'decided_at' => $task->decided_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'created_at' => $task->created_at?->toIso8601String(),
                'logged_minutes' => $minutes->sum(),
                'project' => ['id' => $task->project->id, 'name' => $task->project->name, 'code' => $task->project->code],
                'sub_project' => TaskPresenter::subProject($task->subProject),
            ],
            'sessions' => $sessions->map(fn (TaskWorkSession $s) => TaskPresenter::session($s))->values(),
            'submissions' => $submissions,
            'running' => TaskPresenter::timer($this->timer->running($user)?->load('task')),
            'unlogged' => ['count' => $unlogged->count(), 'minutes' => $unlogged->sum(fn (TaskWorkSession $s) => $s->minutes())],
            'can' => [
                'update' => Gate::allows('update', $task),
                'delete' => Gate::allows('delete', $task),
                'decide' => Gate::allows('decide', $task),
                'claim' => Gate::allows('claim', $task),
                'work' => Gate::allows('work', $task),
                'review' => $submissions->contains('can_review', true),
                'lead' => $isLead,
            ],
            'people' => $isLead ? TaskPresenter::assigneeOptions($task->project_id, $task->assignees->modelKeys()) : [],
            'max_assignees' => TaskRequest::MAX_ASSIGNEES,
            'priorities' => array_map(fn (TaskPriority $p) => $p->value, TaskPriority::cases()),
            'stages' => TaskPresenter::stageOptions(),
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
                'stage_id' => $data['stage_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $direct ? TaskStatus::Todo : TaskStatus::Proposed,
                'priority' => $data['priority'],
                // New tasks go to the end of the list; the lead moves them from there
                'position' => (int) Task::query()->where('sub_project_id', $subProject->id)->lockForUpdate()->max('position') + 1,
                'created_by' => $user->id,
                'due_date' => $data['due_date'] ?? null,
                'estimate_minutes' => $this->minutes($data['estimate_hours'] ?? null),
                'evidence_required' => $direct ? (bool) ($data['evidence_required'] ?? true) : true,
            ]);

            // A proposal is work the proposer plans to do; a lead picks any number of people or leaves it open
            $this->parts->sync($task, $direct ? ($data['assignee_ids'] ?? []) : [$user->id], $user, audit: false);

            $auditor->record($direct ? 'task.created' : 'task.proposed', $task, null, [
                'sub_project_id' => $subProject->id,
                'title' => $task->title,
                'status' => $task->status->value,
                'assignee_ids' => $this->parts->ids($task),
                'stage_id' => $task->stage_id,
            ]);

            return $task;
        });

        return back()->with('status', __($direct ? 'projects::messages.task_created' : 'projects::messages.task_proposed', ['title' => $task->title]));
    }

    public function update(TaskRequest $request, Task $task, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('update', $task);

        $data = $request->validated();
        $user = $request->user();
        $isLead = Gate::allows('lead', $task->subProject);

        DB::transaction(function () use ($task, $data, $user, $isLead, $auditor) {
            $this->parts->lock($task);
            $fields = ['title', 'description', 'priority', 'stage_id', 'due_date', 'estimate_minutes', 'evidence_required'];
            $before = $this->snapshot($task, $fields);

            $task->forceFill([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'priority' => $data['priority'],
                'due_date' => $data['due_date'] ?? null,
                'estimate_minutes' => $this->minutes($data['estimate_hours'] ?? null),
            ]);

            // A form without the field keeps the stage
            if (array_key_exists('stage_id', $data)) {
                $task->stage_id = $data['stage_id'];
            }

            if ($isLead) {
                $task->evidence_required = (bool) ($data['evidence_required'] ?? $task->evidence_required);
            }

            $task->save();
            $auditor->record('task.updated', $task, $before, $this->snapshot($task, $fields));

            // A form without the list keeps the assignees; a change has its own audit entry
            if ($isLead && array_key_exists('assignee_ids', $data)) {
                $this->parts->sync($task, $data['assignee_ids'] ?? [], $user);
            }
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

    /**
     * Puts the task at `index` among the tasks with its status, the group the sub project list shows it in. The order
     * is one sequence for the whole sub project, so a task keeps its place when its status changes. Positions are
     * renumbered 1..n on the way, like document links.
     */
    public function move(Request $request, Task $task, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('reorder', $task);

        $index = (int) $request->validate(['index' => ['required', 'integer', 'min:0', 'max:10000']])['index'];

        DB::transaction(function () use ($task, $index, $auditor) {
            $rows = Task::query()->where('sub_project_id', $task->sub_project_id)->lockForUpdate()
                ->orderBy('position')->orderBy('id')->get(['id', 'status', 'position']);
            $moved = $rows->firstWhere('id', $task->id);

            if ($moved === null) {
                return;
            }

            $group = $rows->filter(fn (Task $row) => $row->status === $moved->status)->pluck('id')->values()->all();
            $from = array_search($task->id, $group, true);
            $others = array_values(array_diff($group, [$task->id]));
            $to = min($index, count($others));

            if ($from === $to) {
                return;
            }

            // Before the task that will follow it in the group, or right after the last one when it goes to the end
            $order = array_values(array_diff($rows->pluck('id')->all(), [$task->id]));
            $at = $to < count($others)
                ? array_search($others[$to], $order, true)
                : array_search($others[count($others) - 1], $order, true) + 1;
            array_splice($order, $at, 0, [$task->id]);

            $positions = $rows->pluck('position', 'id');
            foreach ($order as $i => $id) {
                if ($positions[$id] !== $i + 1) {
                    Task::query()->whereKey($id)->update(['position' => $i + 1]);
                }
            }

            $auditor->record('task.reordered', $task,
                ['sub_project_id' => $task->sub_project_id, 'status' => $moved->status->value, 'index' => $from],
                ['sub_project_id' => $task->sub_project_id, 'status' => $moved->status->value, 'index' => $to]);
        });

        return back();
    }

    public function decide(DecideTaskRequest $request, Task $task): RedirectResponse
    {
        Gate::authorize('decide', $task);

        $data = $request->validated();
        $note = isset($data['note']) ? trim($data['note']) : null;

        if ($data['decision'] === 'approve') {
            $assignees = array_key_exists('assignee_ids', $data) ? ($data['assignee_ids'] ?? []) : null;
            $this->workflow->approveProposal($request->user(), $task, $assignees, $note ?: null);

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

    /** Review of one submission (docs/15): approve that person's part, or send it back with a note. */
    public function review(ReviewTaskRequest $request, Task $task): RedirectResponse
    {
        $data = $request->validated();
        $submission = $task->submissions()->with('submitter')->findOrFail($data['submission_id']);
        Gate::authorize('review', [$task, $submission]);

        $note = isset($data['note']) ? trim($data['note']) : null;
        $name = (string) $submission->submitter?->displayName();

        if ($data['decision'] === 'approve') {
            $this->workflow->approve($request->user(), $task, $submission, $note ?: null);
            $key = $task->status === TaskStatus::Done ? 'projects::messages.review_approved_done' : 'projects::messages.review_approved';

            return back()->with('status', __($key, ['name' => $name]));
        }

        $this->workflow->requestChanges($request->user(), $task, $submission, (string) $note);

        return back()->with('status', __('projects::messages.review_changes', ['name' => $name]));
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
