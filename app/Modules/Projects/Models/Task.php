<?php

namespace App\Modules\Projects\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\PartStatus;
use App\Modules\Projects\Enums\ReviewStatus;
use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'sub_project_id',
        'stage_id',
        'title',
        'description',
        'status',
        'priority',
        'created_by',
        'due_date',
        'estimate_minutes',
        'evidence_required',
        'decided_by',
        'decided_at',
        'decision_note',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_date' => 'date:Y-m-d',
            'evidence_required' => 'boolean',
            'decided_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function subProject(): BelongsTo
    {
        return $this->belongsTo(SubProject::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    /** The people on this task, first assigned first; `pivot` is their TaskAssignee part (docs/15). */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignees')
            ->using(TaskAssignee::class)
            ->withPivot(['id', 'part_status', 'assigned_by', 'assigned_at'])
            ->withTimestamps()
            ->orderBy('task_assignees.assigned_at')
            ->orderBy('task_assignees.id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(TaskAssignee::class);
    }

    /** This person's part, read from the loaded assignees when they are loaded. */
    public function partOf(User|int $user): ?TaskAssignee
    {
        $id = $user instanceof User ? $user->id : $user;

        if ($this->relationLoaded('assignees')) {
            return $this->assignees->firstWhere('id', $id)?->pivot;
        }

        return $this->parts()->where('user_id', $id)->first();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TaskWorkSession::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(TaskSubmission::class);
    }

    /**
     * Submissions waiting for review: pending, sent by a person whose part is submitted, and the latest one that
     * person sent (someone taken off the task and put back later leaves an older pending submission behind).
     *
     * @return HasMany<TaskSubmission, $this>
     */
    public function waitingSubmissions(): HasMany
    {
        return $this->submissions()
            ->where('task_submissions.review_status', ReviewStatus::Pending)
            ->whereIn('task_submissions.submitted_by', fn ($q) => $q->select('user_id')->from('task_assignees')
                ->whereColumn('task_assignees.task_id', 'task_submissions.task_id')
                ->where('part_status', PartStatus::Submitted->value))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('task_submissions as newer')
                ->whereColumn('newer.task_id', 'task_submissions.task_id')
                ->whereColumn('newer.submitted_by', 'task_submissions.submitted_by')
                ->whereColumn('newer.id', '>', 'task_submissions.id'));
    }

    /** Adds `logged_minutes`: timer minutes of closed sessions, by anyone. */
    public function scopeWithLoggedMinutes(Builder $query): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('tasks.*');
        }

        $query->addSelect(['logged_minutes' => TaskWorkSession::query()
            ->selectRaw('coalesce(sum(timestampdiff(minute, started_at, ended_at)), 0)')
            ->whereColumn('task_work_sessions.task_id', 'tasks.id')
            ->whereNotNull('ended_at')]);
    }
}
