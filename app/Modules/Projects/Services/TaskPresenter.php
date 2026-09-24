<?php

namespace App\Modules\Projects\Services;

use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\PipelineStage;
use App\Modules\Projects\Models\ProjectLink;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\ProjectMilestone;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskSubmission;
use App\Modules\Projects\Models\TaskWorkSession;
use Carbon\CarbonImmutable;

/** Arrays sent to the task pages. Only names, initials, and photo links of people; nothing private. */
class TaskPresenter
{
    /** @return array{id: int, name: string, initials: string, photo_url: ?string}|null */
    public static function person(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->displayName(),
            'initials' => $user->initials(),
            'photo_url' => $user->photoUrl(),
        ];
    }

    /**
     * People a lead can put on a task: the active members of the project, plus whoever is on the task already, so
     * the picker shows them ticked even after they left the project or were deactivated (`active` false).
     *
     * @param  list<int>  $currentIds
     * @return list<array{id: int, name: string, initials: string, photo_url: ?string, username: string, active: bool}>
     */
    public static function assigneeOptions(int $projectId, array $currentIds = []): array
    {
        return User::query()
            ->where(fn ($q) => $q
                ->where(fn ($m) => $m->active()->whereIn('id', ProjectMember::query()->select('user_id')->where('project_id', $projectId)))
                ->when($currentIds !== [], fn ($c) => $c->orWhereIn('id', $currentIds)))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'nickname', 'username', 'avatar_path', 'status'])
            ->map(fn (User $u) => [...self::person($u), 'username' => $u->username, 'active' => $u->status === UserStatus::Active])
            ->values()
            ->all();
    }

    /**
     * The people on a task with the state of their part, first assigned first. Needs `assignees` loaded. `name` is
     * the nickname shown in lists; `full_name` goes into the accessible label of stacked avatars (docs/15 section 4).
     *
     * @return list<array{id: int, name: string, full_name: string, initials: string, photo_url: ?string, part_status: string}>
     */
    public static function assignees(Task $task): array
    {
        return $task->assignees
            ->map(fn (User $user) => [...self::person($user), 'full_name' => $user->name, 'part_status' => $user->pivot->part_status->value])
            ->values()
            ->all();
    }

    /**
     * One task for a list. With a viewer, `my_part` is the state of the viewer's own part, or null when they are
     * not on the task.
     *
     * @return array<string, mixed>
     */
    public static function row(Task $task, ?User $viewer = null): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'stage' => self::stage($task->stage),
            'due_date' => $task->due_date?->format('Y-m-d'),
            'estimate_minutes' => $task->estimate_minutes,
            'evidence_required' => $task->evidence_required,
            'assignees' => self::assignees($task),
            'creator' => self::person($task->creator),
            'logged_minutes' => (int) ($task->logged_minutes ?? 0),
            'decision_note' => $task->decision_note,
            'updated_at' => $task->updated_at?->toIso8601String(),
            ...($viewer !== null ? ['my_part' => $task->partOf($viewer)?->part_status->value] : []),
        ];
    }

    /** Task row with where it lives, for lists that mix projects (Tugas saya). @return array<string, mixed> */
    public static function rowWithPlace(Task $task, ?User $viewer = null): array
    {
        return [
            ...self::row($task, $viewer),
            'project' => $task->project ? ['id' => $task->project->id, 'name' => $task->project->name, 'code' => $task->project->code] : null,
            'sub_project' => $task->subProject ? ['id' => $task->subProject->id, 'name' => $task->subProject->name] : null,
        ];
    }

    /** @return array{id: int, name: string, phase: string, is_active: bool}|null */
    public static function stage(?PipelineStage $stage): ?array
    {
        if ($stage === null) {
            return null;
        }

        return ['id' => $stage->id, 'name' => $stage->name, 'phase' => $stage->phase->value, 'is_active' => $stage->is_active];
    }

    /**
     * Every stage in pipeline order, for the stage picker and filter. Inactive ones are included so a task keeps
     * showing its stage; the picker offers them only to the task that already has one.
     *
     * @return list<array{id: int, name: string, phase: string, is_active: bool}>
     */
    public static function stageOptions(): array
    {
        return PipelineStage::query()->ordered()->get()->map(fn (PipelineStage $s) => self::stage($s))->values()->all();
    }

    /**
     * One milestone. `status` is worked out on read (done, overdue, soon, scheduled); `days_until` is negative once
     * the date has passed.
     *
     * @return array<string, mixed>
     */
    public static function milestone(ProjectMilestone $milestone, CarbonImmutable $today): array
    {
        return [
            'id' => $milestone->id,
            'project_id' => $milestone->project_id,
            'name' => $milestone->name,
            'kind' => $milestone->kind->value,
            'due_date' => $milestone->due_date->format('Y-m-d'),
            'done_at' => $milestone->done_at?->toIso8601String(),
            'note' => $milestone->note,
            'status' => $milestone->statusOn($today)->value,
            'days_until' => $milestone->daysUntil($today),
        ];
    }

    /** @return array<string, mixed> */
    public static function link(ProjectLink $link): array
    {
        return [
            'id' => $link->id,
            'category' => $link->category->value,
            'label' => $link->label,
            'url' => $link->url,
            'note' => $link->note,
            'managers_only' => $link->managers_only,
            'service' => $link->service(),
            'host' => $link->host(),
        ];
    }

    /** @return array<string, mixed> */
    public static function subProject(SubProject $subProject): array
    {
        return [
            'id' => $subProject->id,
            'project_id' => $subProject->project_id,
            'name' => $subProject->name,
            'description' => $subProject->description,
            'status' => $subProject->status->value,
            'due_date' => $subProject->due_date?->format('Y-m-d'),
            'lead' => self::person($subProject->lead),
        ];
    }

    /** @return array<string, mixed> */
    public static function session(TaskWorkSession $session): array
    {
        return [
            'id' => $session->id,
            'user' => self::person($session->user),
            'started_at' => $session->started_at->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'minutes' => $session->minutes(),
            'logged' => $session->work_activity_log_id !== null,
        ];
    }

    /** @return array<string, mixed> */
    public static function submission(TaskSubmission $submission): array
    {
        return [
            'id' => $submission->id,
            'note' => $submission->note,
            'evidence_url' => $submission->evidence_url,
            'file' => $submission->file_path !== null ? [
                'name' => $submission->file_name,
                'size' => $submission->file_size,
                'url' => route('tasks.evidence', $submission, absolute: false),
            ] : null,
            'review_status' => $submission->review_status->value,
            'review_note' => $submission->review_note,
            'reviewer' => self::person($submission->reviewer),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'submitted_by' => self::person($submission->submitter),
            'created_at' => $submission->created_at?->toIso8601String(),
        ];
    }

    /** @return array{session_id: int, task_id: int, task_title: string, started_at: string}|null */
    public static function timer(?TaskWorkSession $session): ?array
    {
        if ($session === null) {
            return null;
        }

        return [
            'session_id' => $session->id,
            'task_id' => $session->task_id,
            'task_title' => (string) $session->task?->title,
            'started_at' => $session->started_at->toIso8601String(),
        ];
    }
}
