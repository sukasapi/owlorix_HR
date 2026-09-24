<?php

namespace App\Modules\Projects\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\PartStatus;
use App\Modules\Projects\Enums\ReviewStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskAssignee;
use App\Modules\Projects\Models\TaskSubmission;
use App\Modules\Projects\Models\TaskWorkSession;
use App\Modules\Projects\Models\WorkActivityLog;
use App\Modules\Shared\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * State changes of a task after it exists (docs/13, docs/15): the lead's decision on a proposal, taking a task
 * nobody is on, one assignee sending evidence (which turns their timer sessions into work log rows), and the lead's
 * review of one submission. Parts and the task status change through TaskParts.
 * Callers check the TaskPolicy first. Each change then locks the task row (TaskParts::lock) and checks the policy
 * again inside the transaction, because someone else may have changed the task in between; a request that lost that
 * race gets a 403. Every change writes an audit entry.
 */
class TaskWorkflow
{
    /** Sessions shorter than this are dropped instead of becoming a work log row (a start clicked by mistake). */
    public const MIN_LOG_MINUTES = 1;

    public const EVIDENCE_DISK = 'local';

    public function __construct(
        private readonly TaskTimer $timer,
        private readonly TaskParts $parts,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Approves a proposal. $assigneeIds replaces the assignees (the proposer is the only one until then); null keeps
     * them.
     *
     * @param  list<int>|null  $assigneeIds
     */
    public function approveProposal(User $lead, Task $task, ?array $assigneeIds, ?string $note): void
    {
        DB::transaction(function () use ($lead, $task, $assigneeIds, $note) {
            $this->parts->lock($task);
            abort_unless(Gate::forUser($lead)->allows('decide', $task), 403);

            $task->forceFill([
                'status' => TaskStatus::Todo,
                'decided_by' => $lead->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            if ($assigneeIds !== null) {
                $this->parts->sync($task, $assigneeIds, $lead);
            }

            $this->parts->recompute($task);
            $this->auditor->record('task.proposal_approved', $task, ['status' => TaskStatus::Proposed->value], [
                'status' => $task->status->value,
                'assignee_ids' => $this->parts->ids($task),
            ]);
        });
    }

    public function rejectProposal(User $lead, Task $task, string $note): void
    {
        DB::transaction(function () use ($lead, $task, $note) {
            $this->parts->lock($task);
            abort_unless(Gate::forUser($lead)->allows('decide', $task), 403);

            $task->forceFill([
                'status' => TaskStatus::Rejected,
                'decided_by' => $lead->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();
            $this->auditor->record('task.proposal_rejected', $task, ['status' => TaskStatus::Proposed->value], ['status' => $task->status->value, 'note' => $note]);
        });
    }

    /** Taking a task nobody is on: the person becomes its only assignee. */
    public function claim(User $user, Task $task): void
    {
        DB::transaction(function () use ($user, $task) {
            // Two people claiming at once: the second waits for the lock, then finds an assignee and is refused
            $this->parts->lock($task);
            abort_unless(Gate::forUser($user)->allows('claim', $task), 403);

            $this->parts->sync($task, [$user->id], $user, audit: false);
            $this->auditor->record('task.claimed', $task, ['assignee_ids' => []], ['assignee_ids' => [$user->id], 'person_id' => $user->id]);
        });
    }

    /**
     * Sends one person's evidence for review. Their running timer on this task stops, and every closed session of
     * theirs on this task that has no work log yet becomes one work log row with the task's project, the note, and
     * the evidence link. When they have no session, an optional manual time range makes one row instead. Their part
     * becomes submitted; the sessions of the other assignees are not touched.
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
                // A double click sends twice, or the lead removes the person meanwhile: only a part still open for
                // work is sent
                $this->parts->lock($task);
                abort_unless(Gate::forUser($user)->allows('work', $task), 403);
                $part = TaskAssignee::query()->where('task_id', $task->id)->where('user_id', $user->id)->firstOrFail();

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
                $this->parts->setPart($task, $user->id, PartStatus::Submitted);
                $this->auditor->record('task.submitted', $task, ['status' => $before, 'part_status' => $part->part_status->value], [
                    'status' => $task->status->value,
                    'part_status' => PartStatus::Submitted->value,
                    'person_id' => $user->id,
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

    public function approve(User $lead, Task $task, TaskSubmission $submission, ?string $note): void
    {
        $this->review($lead, $task, $submission, ReviewStatus::Approved, PartStatus::Approved, $note);
    }

    public function requestChanges(User $lead, Task $task, TaskSubmission $submission, string $note): void
    {
        $this->review($lead, $task, $submission, ReviewStatus::ChangesRequested, PartStatus::ChangesRequested, $note);
    }

    /** Reviews one submission: the sender's part follows the review, and the task status follows the parts. */
    private function review(User $lead, Task $task, TaskSubmission $submission, ReviewStatus $review, PartStatus $part, ?string $note): void
    {
        DB::transaction(function () use ($lead, $task, $submission, $review, $part, $note) {
            // Reviewed twice at once, the sender taken off the task, or someone added with an open part meanwhile:
            // the policy, checked again under the lock, refuses all of them
            $this->parts->lock($task);
            $locked = TaskSubmission::query()->whereKey($submission->id)->lockForUpdate()->first();
            abort_unless($locked !== null && Gate::forUser($lead)->allows('review', [$task, $locked]), 403);

            $locked->forceFill([
                'review_status' => $review,
                'reviewed_by' => $lead->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $before = $task->status->value;
            $this->parts->setPart($task, $locked->submitted_by, $part);

            $this->auditor->record('task.reviewed', $task, ['status' => $before, 'part_status' => PartStatus::Submitted->value], [
                'status' => $task->status->value,
                'part_status' => $part->value,
                'person_id' => $locked->submitted_by,
                'submission_id' => $locked->id,
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
