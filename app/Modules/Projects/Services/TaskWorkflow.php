<?php

namespace App\Modules\Projects\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\ReviewStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskSubmission;
use App\Modules\Projects\Models\TaskWorkSession;
use App\Modules\Projects\Models\WorkActivityLog;
use App\Modules\Shared\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * State changes of a task after it exists (docs/13): the lead's decision on a proposal, taking an open task,
 * sending evidence (which turns timer sessions into work log rows), and the lead's review.
 * Callers check the TaskPolicy first; every change writes an audit entry.
 */
class TaskWorkflow
{
    /** Sessions shorter than this are dropped instead of becoming a work log row (a start clicked by mistake). */
    public const MIN_LOG_MINUTES = 1;

    public const EVIDENCE_DISK = 'local';

    public function __construct(
        private readonly TaskTimer $timer,
        private readonly Auditor $auditor,
    ) {}

    public function approveProposal(User $lead, Task $task, ?int $assigneeId, ?string $note): void
    {
        DB::transaction(function () use ($lead, $task, $assigneeId, $note) {
            $task->forceFill([
                'status' => TaskStatus::Todo,
                'assignee_id' => $assigneeId ?? $task->assignee_id,
                'decided_by' => $lead->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();
            $this->auditor->record('task.proposal_approved', $task, ['status' => TaskStatus::Proposed->value], ['status' => $task->status->value, 'assignee_id' => $task->assignee_id]);
        });
    }

    public function rejectProposal(User $lead, Task $task, string $note): void
    {
        DB::transaction(function () use ($lead, $task, $note) {
            $task->forceFill([
                'status' => TaskStatus::Rejected,
                'decided_by' => $lead->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();
            $this->auditor->record('task.proposal_rejected', $task, ['status' => TaskStatus::Proposed->value], ['status' => $task->status->value, 'note' => $note]);
        });
    }

    public function claim(User $user, Task $task): void
    {
        DB::transaction(function () use ($user, $task) {
            $task->forceFill(['assignee_id' => $user->id])->save();
            $this->auditor->record('task.claimed', $task, ['assignee_id' => null], ['assignee_id' => $user->id]);
        });
    }

    /**
     * Sends evidence for review. The running timer on this task stops, and every closed session of the assignee on
     * this task that has no work log yet becomes one work log row with the task's project, the note, and the evidence
     * link. When no session exists, an optional manual time range makes one row instead.
     *
     * @return int number of work log rows created
     */
    public function submit(
        User $user,
        Task $task,
        string $note,
        ?string $evidenceUrl,
        ?UploadedFile $file,
        ?CarbonImmutable $workedFrom = null,
        ?CarbonImmutable $workedUntil = null,
        ?CarbonImmutable $now = null,
    ): int {
        $now ??= CarbonImmutable::now();
        $stored = $file?->storeAs('task-evidence/'.$task->id, Str::random(24).'.'.($file->extension() ?: 'bin'), self::EVIDENCE_DISK);

        try {
            return DB::transaction(function () use ($user, $task, $note, $evidenceUrl, $file, $stored, $workedFrom, $workedUntil, $now) {
                $this->timer->stop($user, $now, $task->id);

                $submission = TaskSubmission::query()->create([
                    'task_id' => $task->id,
                    'submitted_by' => $user->id,
                    'note' => $note,
                    'evidence_url' => $evidenceUrl,
                    'file_path' => $stored ?: null,
                    'file_name' => $file ? Str::limit(preg_replace('/[^\pL\pN ._()-]+/u', '_', $file->getClientOriginalName()) ?: 'bukti', 180, '') : null,
                    'file_mime' => $file?->getMimeType(),
                    'file_size' => $file?->getSize(),
                    'review_status' => ReviewStatus::Pending,
                ]);

                $logs = $this->sessionsToLogs($user, $task, $submission);

                if ($logs === 0 && $workedFrom !== null && $workedUntil !== null) {
                    $this->log($user, $task, $submission, $workedFrom, $workedUntil);
                    $logs = 1;
                }

                $before = $task->status->value;
                $task->forceFill(['status' => TaskStatus::InReview])->save();
                $this->auditor->record('task.submitted', $task, ['status' => $before], [
                    'status' => $task->status->value,
                    'submission_id' => $submission->id,
                    'work_logs' => $logs,
                ]);

                return $logs;
            });
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk(self::EVIDENCE_DISK)->delete($stored);
            }

            throw $e;
        }
    }

    public function approve(User $lead, Task $task, ?string $note): void
    {
        $this->review($lead, $task, ReviewStatus::Approved, TaskStatus::Done, $note);
    }

    public function requestChanges(User $lead, Task $task, string $note): void
    {
        $this->review($lead, $task, ReviewStatus::ChangesRequested, TaskStatus::ChangesRequested, $note);
    }

    private function review(User $lead, Task $task, ReviewStatus $review, TaskStatus $next, ?string $note): void
    {
        DB::transaction(function () use ($lead, $task, $review, $next, $note) {
            $submission = $task->submissions()->where('review_status', ReviewStatus::Pending)->latest('id')->first();
            $submission?->forceFill([
                'review_status' => $review,
                'reviewed_by' => $lead->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $task->forceFill([
                'status' => $next,
                'completed_at' => $next === TaskStatus::Done ? now() : null,
            ])->save();

            $this->auditor->record('task.reviewed', $task, ['status' => TaskStatus::InReview->value], [
                'status' => $next->value,
                'submission_id' => $submission?->id,
                'note' => $note,
            ]);
        });
    }

    private function sessionsToLogs(User $user, Task $task, TaskSubmission $submission): int
    {
        $sessions = TaskWorkSession::query()
            ->where('task_id', $task->id)
            ->where('user_id', $user->id)
            ->whereNotNull('ended_at')
            ->whereNull('work_activity_log_id')
            ->orderBy('started_at')
            ->lockForUpdate()
            ->get();

        $count = 0;

        foreach ($sessions as $session) {
            if ($session->minutes() < self::MIN_LOG_MINUTES) {
                continue;
            }

            $log = $this->log($user, $task, $submission, CarbonImmutable::parse($session->started_at), CarbonImmutable::parse($session->ended_at));
            $session->forceFill(['work_activity_log_id' => $log->id])->save();
            $count++;
        }

        return $count;
    }

    private function log(User $user, Task $task, TaskSubmission $submission, CarbonImmutable $from, CarbonImmutable $until): WorkActivityLog
    {
        $log = WorkActivityLog::query()->create([
            'user_id' => $user->id,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'description' => Str::limit($task->title.': '.$submission->note, 5000, ''),
            'started_at' => $from->utc(),
            'ended_at' => $until->utc(),
            'evidence_url' => Str::limit($submission->evidenceLink(), 500, ''),
        ]);

        $this->auditor->record('activity.created', $log, null, [
            'project_id' => $log->project_id,
            'task_id' => $task->id,
            'started_at' => $log->started_at->toIso8601String(),
            'ended_at' => $log->ended_at->toIso8601String(),
            'source' => 'task_submission',
        ]);

        return $log;
    }
}
