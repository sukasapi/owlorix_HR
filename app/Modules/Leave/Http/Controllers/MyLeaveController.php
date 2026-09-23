<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Access\Permission;
use App\Modules\Leave\Actions\CancelLeave;
use App\Modules\Leave\Actions\RequestLeave;
use App\Modules\Leave\Http\Requests\StoreLeaveRequest;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveApprovers;
use App\Modules\Leave\Services\LeaveBalance;
use App\Modules\Leave\Services\LeaveDays;
use App\Modules\Leave\Services\LeavePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cuti: the person's balance for this year, the request form, and their own requests (docs/14 4). Attachments sit
 * on the private disk and go to the owner, the people who may decide the request, and Superadmin only.
 */
class MyLeaveController extends Controller
{
    public const LIST_LIMIT = 50;

    public function index(Request $request, LeaveBalance $balance, LeavePresenter $presenter): Response
    {
        $viewer = $request->user();
        $now = CarbonImmutable::now();
        $today = Time::workDate($now);
        $year = (int) substr($today, 0, 4);

        $requests = $presenter->withDetails(LeaveRequest::query()->where('user_id', $viewer->id))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        return Inertia::render('leave/Index', [
            'today' => $today,
            // The quota is a record for Superadmin only (owner, 2026-09-23); nobody else receives the numbers
            'balance' => $viewer->hasPermission(Permission::ManageLeave) ? $balance->for($viewer->id, $year) : null,
            'types' => $presenter->activeTypes(),
            'requests' => $requests->map(fn (LeaveRequest $r) => $presenter->item($r, $viewer, $now))->values()->all(),
            'list_limit' => self::LIST_LIMIT,
            'limits' => [
                'attachment_max_kb' => StoreLeaveRequest::ATTACHMENT_MAX_KB,
                'max_span_days' => RequestLeave::MAX_SPAN_DAYS,
                'backdate_days' => RequestLeave::BACKDATE_DAYS,
                'earliest' => CarbonImmutable::parse($today)->subDays(RequestLeave::BACKDATE_DAYS)->toDateString(),
                'latest' => ($year + 1).'-12-31',
            ],
        ]);
    }

    /** Workdays a range would count, shown under the form before sending. Read-only. */
    public function preview(Request $request, LeaveDays $leaveDays, RequestLeave $action, LeaveBalance $balance): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'leave_type_id' => ['nullable', 'integer'],
        ]);

        $errors = $action->rangeErrors($data['start_date'], $data['end_date']);

        if ($errors !== []) {
            return response()->json(['days' => null, 'error' => reset($errors)]);
        }

        $viewer = $request->user();
        $type = isset($data['leave_type_id']) ? LeaveType::query()->find($data['leave_type_id']) : null;
        $days = count($leaveDays->workdays($viewer, $data['start_date'], $data['end_date']));

        return response()->json([
            'days' => $days,
            'error' => $days === 0 ? __('leave::messages.no_workdays') : null,
            'remaining' => $type?->counts_against_quota && $viewer->hasPermission(Permission::ManageLeave)
                ? $balance->for($viewer->id, (int) substr($data['start_date'], 0, 4))['remaining']
                : null,
        ]);
    }

    public function store(StoreLeaveRequest $form, RequestLeave $requestLeave): RedirectResponse
    {
        $type = LeaveType::query()->findOrFail($form->validated('leave_type_id'));

        $leave = $requestLeave(
            $form->user(),
            $type,
            $form->validated('start_date'),
            $form->validated('end_date'),
            $form->validated('reason'),
            $form->file('attachment'),
        );

        return back()->with('status', __('leave::messages.requested', ['type' => $type->name, 'days' => $leave->days]));
    }

    public function cancel(Request $request, LeaveRequest $leave, CancelLeave $cancel): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->id === $leave->user_id || $actor->hasPermission(Permission::ManageLeave), 403);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']], ['note.max' => __('leave::messages.note_max')]);

        try {
            $cancel($actor, $leave, $data['note'] ?? null);
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['leave' => $e->getMessage()]);
        }

        return back()->with('status', $actor->id === $leave->user_id
            ? __('leave::messages.cancelled_own')
            : __('leave::messages.cancelled_other', ['name' => $leave->user?->name ?? '']));
    }

    public function attachment(Request $request, LeaveRequest $leave, LeaveApprovers $approvers): StreamedResponse
    {
        $viewer = $request->user();

        abort_unless(
            $viewer->id === $leave->user_id
                || ($viewer->isActive() && $viewer->hasPermission(Permission::ManageLeave))
                || $approvers->canDecide($viewer, $leave),
            403,
        );
        abort_if(blank($leave->attachment_path) || ! Storage::disk(RequestLeave::DISK)->exists($leave->attachment_path), 404);

        return Storage::disk(RequestLeave::DISK)->download($leave->attachment_path, $leave->attachment_name ?: 'lampiran', [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
