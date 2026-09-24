<?php

namespace App\Modules\Projects\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\PartStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** One person's part of a task (docs/15): their own evidence and review, tracked in `part_status`. */
class TaskAssignee extends Pivot
{
    protected $table = 'task_assignees';

    public $incrementing = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'part_status' => PartStatus::class,
            'assigned_at' => 'datetime',
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
}
