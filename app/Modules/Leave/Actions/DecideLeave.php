<?php

namespace App\Modules\Leave\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveDecision;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveApprovers;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves or rejects a pending leave request (docs/14 4.2). Rejecting needs a note. A decision is final; an
 * approved request can still be cancelled (CancelLeave). Leave never touches attendance or overtime.
 */
class DecideLeave
{
    public function __construct(
        private readonly LeaveApprovers $approvers,
        private readonly Auditor $auditor,
    ) {}

    public function __invoke(User $actor, LeaveRequest $request, LeaveDecision $decision, ?string $note = null): LeaveRequest
    {
        $note = trim((string) $note) !== '' ? trim((string) $note) : null;

        return DB::transaction(function () use ($actor, $request, $decision, $note) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($actor->id === $request->user_id) {
                throw new AuthorizationException(__('leave::messages.own_decision'));
            }

            if (! $this->approvers->canDecide($actor, $request)) {
                throw new AuthorizationException(__('leave::messages.not_decider'));
            }

            if ($request->status !== LeaveStatus::Pending) {
                throw ValidationException::withMessages(['leave' => __('leave::messages.already_closed', [
                    'status' => __('leave::messages.status.'.$request->status->value),
                ])]);
            }

            if ($decision === LeaveDecision::Rejected && $note === null) {
                throw ValidationException::withMessages(['note' => __('leave::messages.reject_note')]);
            }

            $request->update([
                'status' => $decision->status(),
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            $this->auditor->record(
                $decision === LeaveDecision::Approved ? 'leave.approved' : 'leave.rejected',
                $request,
                ['status' => LeaveStatus::Pending->value],
                ['status' => $decision->value, 'note' => $note],
                $actor->id,
            );

            return $request;
        });
    }
}
