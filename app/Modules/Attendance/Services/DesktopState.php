<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Calendar\Services\WorkdayResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Overtime\Services\OvertimeLookup;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;

/**
 * What the desktop app needs to decide on its own (docs/03-architecture.md 4.3): the running shift, today's regular
 * minutes for the 8-hour prompt, overtime shifts still waiting for a report, and shifts the rules ended that the
 * person can still claim overtime for.
 */
class DesktopState
{
    public function __construct(
        private readonly ShiftStateResolver $resolver,
        private readonly WorkdayResolver $calendar,
        private readonly Settings $settings,
        private readonly OvertimeLookup $overtime,
    ) {}

    /** @return array<string, mixed> */
    public function for(User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $today = Time::workDate($now);

        $unclosedDate = Shift::query()->where('user_id', $user->id)->whereNull('clock_out_at')->value('work_date');
        $byDate = $this->resolver->workDates($user->id, array_unique([
            $unclosedDate ?? $today,
            $today,
            ...$this->resolver->attentionDates($user, $now),
        ]), $now);

        $all = array_merge(...array_values($byDate));
        $open = null;

        foreach ($all as $resolved) {
            if ($resolved->result->isLive()) {
                $open = $resolved;
            }
        }

        $workDate = $open?->shift->work_date ?? $today;
        $reportsDue = array_values(array_filter($all, fn (ResolvedShift $r) => $r->result->reportDue()));
        $lateClaims = array_values(array_filter($all, fn (ResolvedShift $r) => $r->result->claimableUntil !== null));
        $requests = $open !== null ? $this->overtime->forShifts([$open->shift->id]) : [];

        return [
            'server_time' => Time::iso($now),
            'work_date' => $workDate,
            'is_workday' => $open?->isWorkday ?? $this->calendar->isWorkday($user, $workDate),
            'regular_limit_minutes' => $open?->shift->regular_limit_minutes ?? $this->settings->int('attendance.regular_limit_minutes'),
            'regular_minutes' => array_sum(array_map(fn (ResolvedShift $r) => $r->result->regularMinutes, $byDate[$workDate])),
            'shift' => $open?->toArray($requests[$open->shift->id]->status->value ?? null),
            'reports_due' => array_map(fn (ResolvedShift $r) => $r->reportDueArray(), $reportsDue),
            'late_claims' => array_map(fn (ResolvedShift $r) => $r->lateClaimArray(), $lateClaims),
        ];
    }
}
