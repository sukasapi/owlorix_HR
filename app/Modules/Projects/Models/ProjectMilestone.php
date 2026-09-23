<?php

namespace App\Modules\Projects\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\MilestoneKind;
use App\Modules\Projects\Enums\MilestoneStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A dated point in a project: an internal target, a client review, or a delivery (docs/14 3.2). */
class ProjectMilestone extends Model
{
    /** An open milestone due today or within this many days counts as "soon". */
    public const SOON_DAYS = 7;

    protected $fillable = [
        'project_id',
        'name',
        'kind',
        'due_date',
        'done_at',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => MilestoneKind::class,
            'due_date' => 'date:Y-m-d',
            'done_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Today in the studio time zone, as a date at midnight. */
    public static function studioToday(): CarbonImmutable
    {
        return CarbonImmutable::now(config('owlorix.display_timezone'))->startOfDay();
    }

    /** Whole days from $today to the due date; negative when it has passed. */
    public function daysUntil(CarbonImmutable $today): int
    {
        $due = CarbonImmutable::parse($this->due_date->format('Y-m-d'), $today->getTimezone())->startOfDay();

        return (int) $today->diffInDays($due, false);
    }

    public function statusOn(CarbonImmutable $today): MilestoneStatus
    {
        if ($this->done_at !== null) {
            return MilestoneStatus::Done;
        }

        $days = $this->daysUntil($today);

        return match (true) {
            $days < 0 => MilestoneStatus::Overdue,
            $days <= self::SOON_DAYS => MilestoneStatus::Soon,
            default => MilestoneStatus::Scheduled,
        };
    }
}
