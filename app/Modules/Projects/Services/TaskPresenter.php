<?php

namespace App\Modules\Projects\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskSubmission;
use App\Modules\Projects\Models\TaskWorkSession;

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

    /** @return array<string, mixed> */
    public static function row(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'estimate_minutes' => $task->estimate_minutes,
            'evidence_required' => $task->evidence_required,
            'assignee' => self::person($task->assignee),
            'creator' => self::person($task->creator),
            'logged_minutes' => (int) ($task->logged_minutes ?? 0),
            'decision_note' => $task->decision_note,
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }

    /** Task row with where it lives, for lists that mix projects (Tugas saya). @return array<string, mixed> */
    public static function rowWithPlace(Task $task): array
    {
        return [
            ...self::row($task),
            'project' => $task->project ? ['id' => $task->project->id, 'name' => $task->project->name, 'code' => $task->project->code] : null,
            'sub_project' => $task->subProject ? ['id' => $task->subProject->id, 'name' => $task->subProject->name] : null,
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
