<?php

namespace App\Modules\Leave\Actions;

use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveDays;
use App\Modules\Shared\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sends a leave request (docs/14 4.2). The server counts the workdays and refuses dates that overlap the person's
 * pending or approved requests. The person's row is locked meanwhile, so two overlapping requests sent at once cannot
 * both pass. Annual leave beyond the quota is not refused: the owner keeps the quota as a record for Superadmin only.
 */
class RequestLeave
{
    public const DISK = 'local';

    /** Longest single request in calendar days; long enough for maternity leave (3 months) */
    public const MAX_SPAN_DAYS = 120;

    /** How far back a request may start, for sick days reported afterwards */
    public const BACKDATE_DAYS = 30;

    public function __construct(
        private readonly LeaveDays $leaveDays,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Checks that need no database lock: dates, span, type, reason. Also used by the preview.
     *
     * @return array<string, string> field => message
     */
    public function rangeErrors(string $start, string $end, ?CarbonImmutable $now = null): array
    {
        $today = CarbonImmutable::parse(Time::workDate($now ?? CarbonImmutable::now()));
        $from = CarbonImmutable::parse($start);
        $until = CarbonImmutable::parse($end);

        return match (true) {
            $until->lessThan($from) => ['end_date' => __('leave::messages.end_after_start')],
            $from->year !== $until->year => ['end_date' => __('leave::messages.same_year')],
            $from->diffInDays($until) + 1 > self::MAX_SPAN_DAYS => ['end_date' => __('leave::messages.too_long', ['days' => self::MAX_SPAN_DAYS])],
            $from->lessThan($today->subDays(self::BACKDATE_DAYS)) => ['start_date' => __('leave::messages.too_early', ['days' => self::BACKDATE_DAYS])],
            $until->year > $today->year + 1 => ['end_date' => __('leave::messages.too_late')],
            default => [],
        };
    }

    public function __invoke(User $person, LeaveType $type, string $start, string $end, ?string $reason, ?UploadedFile $attachment = null): LeaveRequest
    {
        $reason = trim((string) $reason) !== '' ? trim((string) $reason) : null;

        if (! $type->is_active) {
            throw ValidationException::withMessages(['leave_type_id' => __('leave::messages.type_inactive')]);
        }

        if ($type->requires_note && $reason === null) {
            throw ValidationException::withMessages(['reason' => __('leave::messages.reason_required')]);
        }

        if (($errors = $this->rangeErrors($start, $end)) !== []) {
            throw ValidationException::withMessages($errors);
        }

        $path = null;
        $name = null;

        if ($attachment !== null) {
            $path = $attachment->storeAs('leave-attachments', $person->id.'-'.Str::random(20).'.'.$attachment->extension(), self::DISK);
            $name = Str::limit(preg_replace('/[^\pL\pN ._()-]+/u', '_', $attachment->getClientOriginalName()) ?: 'lampiran', 180, '');
        }

        try {
            return DB::transaction(function () use ($person, $type, $start, $end, $reason, $path, $name) {
                User::query()->whereKey($person->id)->lockForUpdate()->first();

                $days = count($this->leaveDays->workdays($person, $start, $end));

                if ($days === 0) {
                    throw ValidationException::withMessages(['end_date' => __('leave::messages.no_workdays')]);
                }

                $clash = LeaveRequest::query()
                    ->where('user_id', $person->id)
                    ->holding()
                    ->overlapping($start, $end)
                    ->with('type:id,name')
                    ->orderBy('start_date')
                    ->first();

                if ($clash !== null) {
                    throw ValidationException::withMessages(['start_date' => __('leave::messages.overlap', [
                        'type' => $clash->type?->name ?? '',
                        'from' => $clash->startDate(),
                        'until' => $clash->endDate(),
                    ])]);
                }

                $request = LeaveRequest::query()->create([
                    'user_id' => $person->id,
                    'leave_type_id' => $type->id,
                    'start_date' => $start,
                    'end_date' => $end,
                    'days' => $days,
                    'reason' => $reason,
                    'attachment_path' => $path,
                    'attachment_name' => $name,
                    'status' => LeaveStatus::Pending,
                ]);

                $this->auditor->record('leave.requested', $request, null, [
                    'type' => $type->code,
                    'start_date' => $start,
                    'end_date' => $end,
                    'days' => $days,
                    'attachment' => $name,
                ], $person->id);

                return $request;
            });
        } catch (Throwable $e) {
            if ($path !== null) {
                Storage::disk(self::DISK)->delete($path);
            }

            throw $e;
        }
    }
}
