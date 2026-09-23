<?php

namespace App\Modules\Leave\Actions;

use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Shared\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a leave request (docs/14 4.2). The owner cancels a pending request any time and an approved one until
 * its start date has passed. Whoever manages leave cancels any pending or approved request, with a note when it
 * is not their own.
 */
class CancelLeave
{
    public const OWN = 'own';

    public const MANAGE = 'manage';

    public function __construct(private readonly Auditor $auditor) {}

    /** How the actor may cancel now: OWN (no note), MANAGE (note required), or null. */
    public function mode(User $actor, LeaveRequest $request, ?CarbonImmutable $now = null): ?string
    {
        if (! $request->status->holdsDays()) {
            return null;
        }

        $today = Time::workDate($now ?? CarbonImmutable::now());

        if ($actor->id === $request->user_id && ($request->status === LeaveStatus::Pending || $request->startDate() >= $today)) {
            return self::OWN;
        }

        return $actor->isActive() && $actor->hasPermission(Permission::ManageLeave) ? self::MANAGE : null;
    }

    public function __invoke(User $actor, LeaveRequest $request, ?string $note = null): LeaveRequest
    {
        $note = trim((string) $note) !== '' ? trim((string) $note) : null;

        return DB::transaction(function () use ($actor, $request, $note) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $request->status->holdsDays()) {
                throw ValidationException::withMessages(['leave' => __('leave::messages.already_closed', [
                    'status' => __('leave::messages.status.'.$request->status->value),
                ])]);
            }

            $mode = $this->mode($actor, $request);

            if ($mode === null) {
                throw new AuthorizationException($actor->id === $request->user_id
                    ? __('leave::messages.cancel_started')
                    : __('leave::messages.cancel_not_allowed'));
            }

            if ($mode === self::MANAGE && $note === null) {
                throw ValidationException::withMessages(['note' => __('leave::messages.cancel_note')]);
            }

            $before = $request->status->value;

            $request->update([
                'status' => LeaveStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_note' => $note,
            ]);

            $this->auditor->record('leave.cancelled', $request, ['status' => $before], ['status' => LeaveStatus::Cancelled->value, 'note' => $note], $actor->id);

            return $request;
        });
    }
}
