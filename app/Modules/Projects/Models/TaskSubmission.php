<?php

namespace App\Modules\Projects\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evidence sent for review: a note plus a link, a file, or both. */
class TaskSubmission extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'review_status' => ReviewStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Link used as the work log evidence: the submitted URL, else the file download, else the task page
     * (a task that needs no evidence still gives the log a link to follow).
     */
    public function evidenceLink(): string
    {
        if (filled($this->evidence_url)) {
            return $this->evidence_url;
        }

        return $this->file_path !== null ? route('tasks.evidence', $this) : route('tasks.show', $this->task_id);
    }
}
