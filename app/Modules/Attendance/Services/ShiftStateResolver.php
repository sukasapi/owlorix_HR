<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\EndReason;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;

/**
 * The one place that answers "what is this shift's state right now". Time rules (prompt timeout, presence check,
 * resume window, missing heartbeats) are applied at read time, so answers never wait for cron.
 * `attendance:settle` saves the same results.
 */
class ShiftStateResolver
{
    /** Shifts closed for review older than this are not searched for a missing work report */
    private const REVIEW_LOOKBACK_DAYS = 31;

    public function __construct(
        private readonly WorkDateCalculator $dates,
        private readonly Settings $settings,
    ) {}

    /** @return list<ResolvedShift> cancelled clock-ins left out */
    public function workDate(int $userId, string $workDate, ?CarbonImmutable $now = null): array
    {
        return array_values(array_filter(
            $this->dates->calculate($userId, $workDate, $now ?? CarbonImmutable::now()),
            fn (ResolvedShift $resolved) => ! $resolved->result->cancelled,
        ));
    }

    public function shift(Shift $shift, ?CarbonImmutable $now = null): ?ResolvedShift
    {
        foreach ($this->workDate($shift->user_id, $shift->work_date, $now) as $resolved) {
            if ($resolved->shift->is($shift)) {
                return $resolved;
            }
        }

        return null;
    }

    /** The shift the person is clocked in on, if the time rules have not ended it. */
    public function openShift(User $user, ?CarbonImmutable $now = null): ?ResolvedShift
    {
        $shift = Shift::query()->where('user_id', $user->id)->whereNull('clock_out_at')->first();

        if ($shift === null) {
            return null;
        }

        $resolved = $this->shift($shift, $now);

        return $resolved?->result->isLive() ? $resolved : null;
    }

    /**
     * Work dates whose shifts may need an answer from the person: a shift still running (the time rules may have
     * ended it), a work report still due (3.4.2, also on shifts closed for review), or a late claim still possible
     * (3.3.6, 3.5.5).
     *
     * @return list<string>
     */
    public function attentionDates(User $user, CarbonImmutable $now): array
    {
        $claimSince = $now->subHours($this->settings->int('overtime.late_claim_hours'));
        $reviewSince = Time::workDate($now->subDays(self::REVIEW_LOOKBACK_DAYS));

        return Shift::query()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q
                ->whereNull('clock_out_at')
                ->orWhere('status', ShiftStatus::ReportDue)
                ->orWhere(fn ($q) => $q->where('status', ShiftStatus::NeedsReview)->where('overtime_minutes', '>', 0)->where('work_date', '>=', $reviewSince))
                ->orWhere(fn ($q) => $q->where('end_reason', EndReason::AutoNoAnswer)->where('clock_out_at', '>=', Time::db($claimSince))))
            ->orderBy('work_date')
            ->distinct()
            ->pluck('work_date')
            ->all();
    }

    /**
     * @param  iterable<string>  $workDates
     * @return array<string, list<ResolvedShift>>
     */
    public function workDates(int $userId, iterable $workDates, CarbonImmutable $now): array
    {
        $resolved = [];

        foreach ($workDates as $date) {
            $resolved[$date] ??= $this->workDate($userId, $date, $now);
        }

        return $resolved;
    }
}
