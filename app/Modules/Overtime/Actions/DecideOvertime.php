<?php

namespace App\Modules\Overtime\Actions;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Overtime\Enums\Decision;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeDecision;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Overtime\Services\OvertimeApprovers;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approve or reject an overtime request (3.4.5). Decisions only decide whether minutes count; they never block work
 * (3.4.6). Changing a decision needs the change permission and a note (3.4.7). Every decision is audited.
 */
class DecideOvertime
{
    public function __construct(
        private readonly OvertimeApprovers $approvers,
        private readonly Auditor $auditor,
    ) {}

    public function __invoke(User $actor, OvertimeRequest $request, Decision $decision, ?string $note = null): OvertimeDecision
    {
        $note = trim((string) $note) !== '' ? trim((string) $note) : null;

        if ($actor->is($request->user)) {
            throw new AuthorizationException('Lembur sendiri tidak bisa diputuskan sendiri.');
        }

        $changing = $request->status !== OvertimeStatus::Pending;

        if ($changing && ! $actor->hasPermission(Permission::ChangeOvertimeDecisions)) {
            throw new AuthorizationException('Keputusan lembur hanya bisa diubah oleh Project Director atau Superadmin.');
        }

        if (! $changing && ! $this->approvers->canApprove($actor, $request)) {
            throw new AuthorizationException('Kamu tidak bisa memutuskan lembur orang ini.');
        }

        // 3.4.5: decisions are made afterwards, on finished overtime with its work report
        if ($request->ended_at === null || $request->work_report === null) {
            throw ValidationException::withMessages([
                'overtime' => 'Lembur ini belum selesai atau laporan kerjanya belum ditulis.',
            ]);
        }

        if ($note === null && ($changing || $decision === Decision::Rejected)) {
            throw ValidationException::withMessages([
                'note' => $changing ? 'Tulis alasan perubahan keputusan.' : 'Tulis catatan saat menolak lembur.',
            ]);
        }

        return DB::transaction(function () use ($actor, $request, $decision, $note, $changing) {
            $before = ['status' => $request->status->value];

            $row = $request->decisions()->create([
                'decided_by' => $actor->id,
                'decision' => $decision,
                'note' => $note,
                'decided_at' => now(),
            ]);

            $request->update(['status' => $decision->status()]);

            $this->auditor->record(
                $changing ? 'overtime.decision_changed' : 'overtime.decided',
                $request,
                $before,
                ['status' => $decision->value, 'note' => $note],
                $actor->id,
            );

            return $row;
        });
    }
}
