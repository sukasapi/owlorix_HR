<?php

namespace App\Modules\Attendance\Notifications;

use App\Modules\Attendance\Models\Correction;
use App\Modules\Identity\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Tells the person that one of their shift times was corrected (3.10.3): which time, from what to what, by whom and
 * why. Stored in the database only; showing it to the person is a later step.
 */
class CorrectionAppliedNotification extends Notification
{
    public function __construct(
        private readonly Correction $correction,
        private readonly User $appliedBy,
        private readonly string $workDate,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'correction.applied';
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'correction_id' => $this->correction->id,
            'shift_id' => $this->correction->shift_id,
            'work_date' => $this->workDate,
            'field' => $this->correction->field->value,
            'old_value' => $this->correction->old_value,
            'new_value' => $this->correction->new_value,
            'reason' => $this->correction->reason,
            'applied_by' => ['id' => $this->appliedBy->id, 'name' => $this->appliedBy->name],
        ];
    }
}
