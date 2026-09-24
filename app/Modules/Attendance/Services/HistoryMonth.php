<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Calculation\ShiftRules;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Calendar\Services\WorkdayResolver;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Services\OvertimeHistory;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Props for the web "Riwayat" page: one studio month (Asia/Jakarta) of the person's own shifts, grouped by work date,
 * with month totals. Shifts are resolved for this moment (HistoryShifts), so a shift the time rules have ended shows
 * as ended even before cron saves it. Totals add up the same resolved minutes the dates show. The number of queries
 * does not grow with the number of shifts.
 */
class HistoryMonth
{
    public function __construct(
        private readonly HistoryShifts $shifts,
        private readonly WorkdayResolver $calendar,
        private readonly OvertimeHistory $overtime,
        private readonly WebClock $webClock,
        private readonly Settings $settings,
        private readonly WeekTarget $weekTarget,
    ) {}

    /** Parses `YYYY-MM`; anything else falls back to the current studio month. */
    public function resolveMonth(mixed $value, CarbonImmutable $now): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $m) && (int) $m[1] >= 2000 && (int) $m[1] <= 2999) {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, 'UTC');
        }

        $local = $now->setTimezone(Time::zone());

        return CarbonImmutable::create($local->year, $local->month, 1, 0, 0, 0, 'UTC');
    }

    /** @return array<string, mixed> */
    public function build(User $user, CarbonImmutable $month, CarbonImmutable $now): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth()->startOfDay();
        $today = Time::workDate($now);
        $currentMonth = substr($today, 0, 7);

        $verdicts = $this->calendar->range($user, $start, $end);
        $set = $this->shifts->forRange($user, $start->toDateString(), $end->toDateString(), $now, $verdicts);
        $requests = $this->overtime->forShifts($set->shiftIds());

        $paths = [];
        $deviceIds = [];

        foreach ($set->shifts as $resolvedShifts) {
            foreach ($resolvedShifts as $resolved) {
                $paths[$resolved->shift->id] = $set->devicePath($resolved);

                foreach ($paths[$resolved->shift->id] as $step) {
                    $deviceIds[$step['device_id']] = true;
                }
            }
        }

        $deviceNames = $deviceIds === [] ? [] : Device::query()->whereIn('id', array_keys($deviceIds))->pluck('hostname', 'id')->all();

        $totals = [
            'days_worked' => 0,
            'regular_minutes' => 0,
            'overtime_minutes' => 0,
            'overtime_approved_minutes' => 0,
            'overtime_pending_minutes' => 0,
            'overtime_rejected_minutes' => 0,
            'idle_minutes' => 0,
            'short_days' => 0,
            'non_workdays_worked' => 0,
            'has_live_shift' => false,
            'has_web_shifts' => false,
        ];

        $days = [];

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $key = $date->toDateString();
            $resolvedShifts = $set->shifts[$key] ?? [];
            $verdict = $verdicts[$key];

            $day = [
                'date' => $key,
                'weekday' => $date->dayOfWeekIso,
                'is_today' => $key === $today,
                'is_future' => $key > $today,
                'calendar' => $verdict->toArray(),
                'is_workday' => $resolvedShifts[0]->isWorkday ?? $verdict->isWorkday,
                'regular_minutes' => 0,
                'overtime_minutes' => 0,
                'idle_minutes' => 0,
                'is_short' => false,
                'shifts' => [],
            ];

            foreach ($resolvedShifts as $resolved) {
                $r = $resolved->result;
                $request = $requests[$resolved->shift->id] ?? null;
                $devices = array_map(fn (array $step) => [
                    'at' => Time::iso($step['at']),
                    'device_id' => $step['device_id'],
                    'name' => $deviceNames[$step['device_id']] ?? $step['device_id'],
                    'is_web' => ShiftRules::isWebDevice($step['device_id']),
                ], $paths[$resolved->shift->id]);
                $webSteps = count(array_filter($devices, fn (array $step) => $step['is_web']));

                $shift = $resolved->toArray($request?->status->value);
                $shift['is_live'] = $r->isLive();
                $shift['devices'] = $devices;
                // 3.11.4: a browser cannot see keyboard or mouse input, so time on the web has no idle periods
                $shift['idle_detection'] = match (true) {
                    $webSteps === 0 => 'full',
                    $webSteps === count($devices) => 'none',
                    default => 'partial',
                };

                if ($shift['overtime'] !== null) {
                    $shift['overtime']['decisions'] = $request !== null ? $this->overtime->decisions($request) : [];
                }

                $day['shifts'][] = $shift;
                $day['regular_minutes'] += $r->regularMinutes;
                $day['overtime_minutes'] += $r->overtimeMinutes;
                $day['idle_minutes'] += $r->idleMinutes;
                $day['is_short'] = $day['is_short'] || $resolved->isShort;

                // Overtime without a request yet counts as waiting, as it would be once the request is saved (3.4.3)
                $status = $request->status ?? OvertimeStatus::Pending;
                $totals['overtime_'.$status->value.'_minutes'] += $r->overtimeMinutes;
                $totals['has_live_shift'] = $totals['has_live_shift'] || $r->isLive();
                $totals['has_web_shifts'] = $totals['has_web_shifts'] || $webSteps > 0;
            }

            if ($day['shifts'] !== []) {
                $totals['days_worked']++;
                $totals['regular_minutes'] += $day['regular_minutes'];
                $totals['overtime_minutes'] += $day['overtime_minutes'];
                $totals['idle_minutes'] += $day['idle_minutes'];
                $totals['short_days'] += $day['is_short'] ? 1 : 0;
                $totals['non_workdays_worked'] += $day['is_workday'] ? 0 : 1;
            }

            $days[] = $day;
        }

        $value = $start->format('Y-m');

        return [
            'month' => [
                'value' => $value,
                'previous' => $start->subMonthNoOverflow()->format('Y-m'),
                // Nothing is recorded ahead of the current month
                'next' => $value < $currentMonth ? $start->addMonthNoOverflow()->format('Y-m') : null,
                'current' => $currentMonth,
            ],
            'today' => $today,
            'totals' => $totals,
            'days' => $days,
            // Reports due and late claims can also be written on Hari ini while web clock-in is on (3.11.7)
            'web_clock_in_enabled' => $this->webClock->enabled(),
            'weeks' => $this->weeks($user, $start, $end, $today, $now),
            // Rule values the page quotes, so its sentences follow the settings
            'rules' => [
                'regular_limit_minutes' => $this->settings->int('attendance.regular_limit_minutes'),
                'prompt_auto_close_minutes' => $this->settings->int('attendance.prompt_auto_close_minutes'),
                'overtime_idle_answer_minutes' => $this->settings->int('attendance.overtime_idle_answer_minutes'),
                'resume_window_minutes' => $this->settings->int('attendance.resume_window_minutes'),
                'late_claim_hours' => $this->settings->int('overtime.late_claim_hours'),
            ],
        ];
    }

    /**
     * Every studio week (Monday to Sunday) that touches the month and has started, from the week of the person's
     * first shift, against their weekly target (docs/02 3.12). Weeks run past the month edges, so a week is whole.
     * Null for a type without a target.
     *
     * @return list<array<string, mixed>>|null
     */
    private function weeks(User $user, CarbonImmutable $start, CarbonImmutable $end, string $today, CarbonImmutable $now): ?array
    {
        if ($this->weekTarget->rule($user) === null) {
            return null;
        }

        $current = WeekTarget::mondayOf($today)->toDateString();
        // Weeks before the person's first shift are before they started working here, not weeks short of the target
        $first = Shift::query()->where('user_id', $user->id)->min('work_date');
        $from = WeekTarget::mondayOf(min((string) ($first ?? $today), $today))->toDateString();
        $mondays = [];

        for ($monday = WeekTarget::mondayOf($start->toDateString()); $monday->toDateString() <= $end->toDateString() && $monday->toDateString() <= $today; $monday = $monday->addWeek()) {
            if ($monday->toDateString() >= $from) {
                $mondays[] = $monday;
            }
        }

        $weeks = array_map(
            fn (array $week) => [...$week, 'is_current' => $week['week_start'] === $current],
            $this->weekTarget->weeksOf($user, $mondays, $now),
        );

        return $weeks;
    }
}
