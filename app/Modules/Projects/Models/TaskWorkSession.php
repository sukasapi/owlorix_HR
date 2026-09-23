<?php

namespace App\Modules\Projects\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One timer run on a task. Closed sessions become work log rows when the task is sent for review. */
class TaskWorkSession extends Model
{
    protected $guarded = ['id', 'open_user_id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activityLog(): BelongsTo
    {
        return $this->belongsTo(WorkActivityLog::class, 'work_activity_log_id');
    }

    public function minutes(): int
    {
        return $this->ended_at === null ? 0 : intdiv((int) $this->started_at->diffInSeconds($this->ended_at, true), 60);
    }
}
