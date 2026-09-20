<?php

namespace App\Modules\Overtime\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Support\Time;
use App\Modules\Overtime\Actions\DecideOvertime;
use App\Modules\Overtime\Enums\Decision;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Http\Requests\ApprovalBulkRequest;
use App\Modules\Overtime\Http\Requests\ApprovalDecisionRequest;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Overtime\Services\ApprovalInbox;
use App\Modules\Overtime\Services\OvertimeApprovers;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Persetujuan: Management decides overtime afterwards (3.4.5), a Project Director or Superadmin changes a decision
 * with a note (3.4.7). Every decision goes through DecideOvertime; its refusals come back as form errors so the
 * page shows them next to the request.
 */
class ApprovalsController extends Controller
{
    public function index(Request $request, ApprovalInbox $inbox): Response
    {
        return Inertia::render('approvals/Index', [
            ...$inbox->for($request->user()),
            'bulk_result' => $request->session()->get('approvals_bulk'),
        ]);
    }

    public function decide(ApprovalDecisionRequest $form, OvertimeRequest $overtimeRequest, DecideOvertime $decide): RedirectResponse
    {
        $actor = $form->user();
        $decision = Decision::from($form->validated('decision'));

        try {
            $changed = DB::transaction(function () use ($form, $overtimeRequest, $decide, $actor, $decision) {
                $request = OvertimeRequest::query()->lockForUpdate()->findOrFail($overtimeRequest->id);
                $this->ensureUnchangedSinceSeen($request, $form);
                $changing = $request->status !== OvertimeStatus::Pending;

                $decide($actor, $request, $decision, $form->validated('note'));

                return $changing;
            });
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['overtime' => $e->getMessage()]);
        }

        $name = $overtimeRequest->user?->name ?? '';
        $word = $decision === Decision::Approved ? 'disetujui' : 'ditolak';

        return back()->with('status', $changed
            ? "Keputusan lembur {$name} diubah jadi {$word}."
            : "Lembur {$name} {$word}.");
    }

    /**
     * Approves the selected requests that need no closer look. Requests with a flag, still running, waiting for a
     * report, or changed since the page loaded are skipped and counted by reason.
     */
    public function bulk(ApprovalBulkRequest $form, DecideOvertime $decide, ApprovalInbox $inbox, OvertimeApprovers $approvers): RedirectResponse
    {
        $actor = $form->user();
        $result = ['approved' => 0, 'skipped' => ['flagged' => 0, 'not_ready' => 0, 'changed' => 0, 'not_allowed' => 0]];

        foreach ($form->validated('items') as $item) {
            try {
                $outcome = DB::transaction(function () use ($item, $actor, $decide, $inbox, $approvers) {
                    $request = OvertimeRequest::query()->lockForUpdate()->find($item['id']);

                    if ($request === null || $request->status !== OvertimeStatus::Pending || $request->minutes !== (int) $item['seen_minutes']) {
                        return 'changed';
                    }

                    if (! $approvers->canApprove($actor, $request)) {
                        return 'not_allowed';
                    }

                    if ($inbox->blocked($request) !== null) {
                        return 'not_ready';
                    }

                    if ($inbox->flags($request) !== []) {
                        return 'flagged';
                    }

                    $decide($actor, $request, Decision::Approved);

                    return 'approved';
                });
            } catch (AuthorizationException) {
                $outcome = 'not_allowed';
            } catch (ValidationException) {
                $outcome = 'not_ready';
            }

            if ($outcome === 'approved') {
                $result['approved']++;
            } else {
                $result['skipped'][$outcome]++;
            }
        }

        return back()->with('approvals_bulk', $result);
    }

    /** Refuses a decision on something other than what the approver was looking at. */
    private function ensureUnchangedSinceSeen(OvertimeRequest $request, ApprovalDecisionRequest $form): void
    {
        $latest = $request->latestDecision()->with('decider:id,name')->first();
        $seenDecision = $form->validated('seen_decision_id');

        if ($request->status->value !== $form->validated('seen_status') || $latest?->id !== ($seenDecision === null ? null : (int) $seenDecision)) {
            if ($request->status === OvertimeStatus::Pending) {
                throw ValidationException::withMessages([
                    'overtime' => 'Lembur ini kembali menunggu keputusan karena menitnya berubah. Keputusanmu tidak dikirim.',
                ]);
            }

            $at = $latest?->decided_at->setTimezone(Time::zone());

            throw ValidationException::withMessages([
                'overtime' => sprintf(
                    'Lembur ini sudah diputuskan oleh %s pada %s pukul %s (%s). Keputusanmu tidak dikirim.',
                    $latest?->decider?->name ?? 'orang lain',
                    $at?->locale('id')->translatedFormat('j M') ?? '',
                    $at?->format('H.i') ?? '',
                    $request->status === OvertimeStatus::Approved ? 'disetujui' : 'ditolak',
                ),
            ]);
        }

        $seenMinutes = (int) $form->validated('seen_minutes');

        if ($request->minutes !== $seenMinutes) {
            throw ValidationException::withMessages([
                'overtime' => sprintf(
                    'Menit lembur ini berubah dari %s jadi %s. Periksa lagi sebelum memutuskan. Keputusanmu tidak dikirim.',
                    $this->duration($seenMinutes),
                    $this->duration($request->minutes),
                ),
            ]);
        }
    }

    private function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return "{$rest} mnt";
        }

        return $rest === 0 ? "{$hours} j" : "{$hours} j {$rest} mnt";
    }
}
