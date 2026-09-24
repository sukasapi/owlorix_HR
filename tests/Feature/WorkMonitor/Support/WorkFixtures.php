<?php

namespace Tests\Feature\WorkMonitor\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use App\Modules\Projects\Enums\PartStatus;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskAssignee;
use App\Modules\Projects\Models\TaskSubmission;
use App\Modules\Projects\Models\TaskWorkSession;
use App\Modules\Projects\Models\WorkActivityLog;
use Carbon\CarbonImmutable;

/** Projects, tasks, logs, and reviews for Monitor kerja and Beban kerja tests, built without the HTTP flow. */
final class WorkFixtures
{
    public static function project(string $name, ?int $budgetMinutes = null): Project
    {
        return Project::query()->create(['name' => $name, 'status' => ProjectStatus::Active, 'budget_minutes' => $budgetMinutes]);
    }

    public static function sub(Project $project, string $name, ?User $lead = null): SubProject
    {
        return $project->subProjects()->create(['name' => $name, 'status' => ProjectStatus::Active, 'lead_user_id' => $lead?->id]);
    }

    public static function team(string $name, User $lead, User ...$members): Team
    {
        $team = Team::query()->create(['name' => $name, 'lead_user_id' => $lead->id]);
        $team->members()->attach(array_map(fn (User $u) => $u->id, $members));

        return $team;
    }

    /**
     * A task with its assignees. Each part follows the task status the way the migration maps it (docs/15): in
     * review is submitted, changes requested stays, done is approved, anything else is open.
     *
     * @param  User|list<User>|null  $assignees
     * @param  array<string, mixed>  $extra
     */
    public static function task(SubProject $sub, User|array|null $assignees, TaskStatus $status = TaskStatus::Todo, array $extra = []): Task
    {
        $people = $assignees === null ? [] : (is_array($assignees) ? $assignees : [$assignees]);

        $task = Task::query()->create([
            'project_id' => $sub->project_id,
            'sub_project_id' => $sub->id,
            'title' => $extra['title'] ?? 'Blocking shot 010',
            'status' => $status,
            'priority' => 'normal',
            'created_by' => $people[0]->id ?? User::query()->value('id'),
            'due_date' => $extra['due_date'] ?? null,
            'estimate_minutes' => $extra['estimate_minutes'] ?? null,
            'stage_id' => $extra['stage_id'] ?? null,
            'completed_at' => $extra['completed_at'] ?? ($status === TaskStatus::Done ? CarbonImmutable::now() : null),
        ]);

        if (isset($extra['created_at'])) {
            $task->forceFill(['created_at' => $extra['created_at']])->save();
        }

        $part = match ($status) {
            TaskStatus::InReview => PartStatus::Submitted,
            TaskStatus::ChangesRequested => PartStatus::ChangesRequested,
            TaskStatus::Done => PartStatus::Approved,
            default => PartStatus::Open,
        };

        foreach ($people as $person) {
            TaskAssignee::query()->create(['task_id' => $task->id, 'user_id' => $person->id, 'part_status' => $part, 'assigned_at' => CarbonImmutable::now()]);
        }

        return $task;
    }

    public static function log(User $user, Project $project, int $minutes, string $startUtc, ?Task $task = null): WorkActivityLog
    {
        $from = CarbonImmutable::parse($startUtc, 'UTC');

        return WorkActivityLog::query()->create([
            'user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task?->id,
            'description' => 'Animasi shot 010', 'started_at' => $from, 'ended_at' => $from->addMinutes($minutes),
            'evidence_url' => 'https://drive.example/shot',
        ]);
    }

    public static function session(Task $task, User $user, int $minutes, string $startUtc = '2026-09-21 02:00:00'): TaskWorkSession
    {
        $from = CarbonImmutable::parse($startUtc, 'UTC');

        return TaskWorkSession::query()->create(['task_id' => $task->id, 'user_id' => $user->id, 'started_at' => $from, 'ended_at' => $from->addMinutes($minutes)]);
    }

    public static function review(Task $task, User $by, string $status, string $sentUtc, string $reviewedUtc): TaskSubmission
    {
        $submission = TaskSubmission::query()->create([
            'task_id' => $task->id, 'submitted_by' => $by->id, 'note' => 'Render pass pertama', 'evidence_url' => 'https://drive.example/r',
            'review_status' => $status, 'reviewed_at' => CarbonImmutable::parse($reviewedUtc, 'UTC'),
        ]);
        $submission->forceFill(['created_at' => CarbonImmutable::parse($sentUtc, 'UTC')])->save();

        return $submission;
    }
}
