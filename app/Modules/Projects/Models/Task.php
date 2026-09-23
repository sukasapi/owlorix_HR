<?php

namespace App\Modules\Projects\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'assignee_id',
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
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
