<?php

namespace App\Modules\Leave\Services;

use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Actions\CancelLeave;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One leave request as the pages read it, with what the viewer may do with it. Used by Cuti, Persetujuan cuti,
 * and Admin cuti, so the three pages show a request the same way.
 */
class LeavePresenter
{
    public function __construct(
        private readonly LeaveApprovers $approvers,
        private readonly CancelLeave $cancel,
    ) {}

    /**
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function withDetails(Builder $query): Builder
    {
        return $query->with([
            'type:id,code,name,counts_against_quota,requires_note,is_active',
            'user' => fn ($q) => $q->select('id', 'name', 'username', 'status'),
            'user.teams' => fn ($q) => $q->select('teams.id', 'teams.name')->orderBy('name'),
            'decider:id,name',
            'canceller:id,name',
        ]);
    }

    /**
     * @param  bool|null  $canDecide  already known by the caller (Persetujuan cuti reads its pending list from
     *                                PendingLeave), so it is not checked again per request
     * @return array<string, mixed>
     */
    public function item(LeaveRequest $request, User $viewer, ?CarbonImmutable $now = null, ?bool $canDecide = null): array
    {
        $person = $request->user;
        $cancelMode = $this->cancel->mode($viewer, $request, $now);
        $decided = in_array($request->status, [LeaveStatus::Approved, LeaveStatus::Rejected], true)
            || ($request->status === LeaveStatus::Cancelled && $request->decided_at !== null);

        return [
            'id' => $request->id,
            'status' => $request->status->value,
            'type' => $request->type === null ? null : [
                'id' => $request->type->id,
                'name' => $request->type->name,
                'counts_against_quota' => $request->type->counts_against_quota,
            ],
            'start_date' => $request->startDate(),
            'end_date' => $request->endDate(),
            'days' => $request->days,
            'reason' => $request->reason,
            'person' => $person === null ? null : [
                'id' => $person->id,
                'name' => $person->name,
                'initials' => $person->initials(),
                'status' => $person->status?->value,
                'teams' => $person->relationLoaded('teams') ? $person->teams->pluck('name')->all() : [],
            ],
            'attachment' => $request->attachment_path === null ? null : [
                'name' => $request->attachment_name ?: 'lampiran',
                'url' => route('leave.attachment', $request, absolute: false),
            ],
            'decision' => ! $decided || $request->decided_at === null ? null : [
                'decision' => $request->status === LeaveStatus::Rejected ? 'rejected' : 'approved',
                'by' => $request->decider?->name,
                'at' => Time::iso($request->decided_at),
                'note' => $request->decision_note,
            ],
            'cancellation' => $request->status !== LeaveStatus::Cancelled ? null : [
                'by' => $request->canceller?->name,
                'by_owner' => $request->cancelled_by !== null && $request->cancelled_by === $request->user_id,
                'at' => Time::iso($request->cancelled_at),
                'note' => $request->cancel_note,
            ],
            'created_at' => Time::iso($request->created_at),
            'can_cancel' => $cancelMode !== null,
            'cancel_needs_note' => $cancelMode === CancelLeave::MANAGE,
            'can_decide' => $request->status === LeaveStatus::Pending && ($canDecide ?? $this->approvers->canDecide($viewer, $request)),
        ];
    }

    /** @return list<array{id: int, code: string, name: string, counts_against_quota: bool, requires_note: bool, is_active: bool}> */
    public function activeTypes(): array
    {
        return LeaveType::query()->where('is_active', true)->ordered()->get()
            ->map(fn (LeaveType $type) => $type->toSummary())->values()->all();
    }
}
