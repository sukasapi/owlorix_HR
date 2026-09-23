<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Calculation\IdlePeriodResult;
use App\Modules\Attendance\Calculation\ShiftRules;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Calendar\Services\WorkdayResolver;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Overtime\Services\OvertimeLookup;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;

/**
 * Data for the web "Hari ini" page: today's shifts with their state right now, plus what the page needs to clock in
 * and answer from the browser (3.11). A shift that started yesterday and is still running is included.
 */
class TodaySummary
{
    public function __construct(
        private readonly ShiftStateResolver $resolver,
        private readonly WorkdayResolver $calendar,
        private readonly Settings $settings,
        private readonly OvertimeLookup $overtime,
        private readonly WebDevice $webDevice,
        private readonly WebClock $webClock,
    ) {}

    /**
     * @return array<string, mixed> keys: date, is_workday, status, regular_limit_minutes, regular_minutes,
     *                              overtime_minutes, idle_minutes, regular_ends_at, idle_periods, shifts, web_clock_in_enabled,
     *                              this_browser_device_id, open_shift, undo_until, prompt_deadline_at, presence_check,
     *                              reports_due, late_claims, rules
     */
    public function for(User $user, ?CarbonImmutable $now = null, ?string $browserDeviceId = null): array
    {
        $now ??= CarbonImmutable::now();
        $today = Time::workDate($now);
        $browserDeviceId ??= $this->webDevice->idFrom(request());

        $unclosedDate = Shift::query()->where('user_id', $user->id)->whereNull('clock_out_at')->value('work_date');
        $byDate = $this->resolver->workDates($user->id, array_unique(array_filter([
            $today,
            $unclosedDate,
            ...$this->resolver->attentionDates($user, $now),
        ])), $now);

        $all = array_merge(...array_values($byDate));
        $open = null;

        foreach ($all as $resolved) {
            if ($resolved->result->isLive()) {
                $open = $resolved;
            }
        }

        $todays = $byDate[$today];
        $carriedOver = $open !== null && $open->shift->work_date < $today;
        $shifts = $carriedOver ? [$open, ...$todays] : $todays;
        // A shift still running after midnight belongs to the date it started (3.1), so the big number keeps that
        // date's regular total instead of dropping to today's 0 while the shift runs on
        $regularShifts = $carriedOver ? ($byDate[$open->shift->work_date] ?? [$open]) : $todays;

        $requests = $this->overtime->forShifts(array_map(fn (ResolvedShift $r) => $r->shift->id, $shifts));
        $current = $open ?? ($shifts === [] ? null : $shifts[array_key_last($shifts)]);

        $idle = [];

        foreach ($shifts as $resolved) {
            foreach ($resolved->result->idlePeriods as $period) {
                $idle[] = $this->idlePeriod($resolved, $period);
            }
        }

        $onThisBrowser = $open !== null && $browserDeviceId !== null && $open->result->deviceId === $browserDeviceId;
        $undoUntil = $open?->result->clockInAt->addSeconds(ShiftRules::CANCEL_WINDOW_SECONDS);

        return [
            'date' => $today,
            'is_workday' => $todays[0]->isWorkday ?? $this->calendar->isWorkday($user, $today),
            'status' => $open?->result->status->value ?? 'signed_out',
            'regular_limit_minutes' => $current?->shift->regular_limit_minutes ?? $this->settings->int('attendance.regular_limit_minutes'),
            'regular_minutes' => array_sum(array_map(fn (ResolvedShift $r) => $r->result->regularMinutes, $regularShifts)),
            'overtime_minutes' => array_sum(array_map(fn (ResolvedShift $r) => $r->result->overtimeMinutes, $shifts)),
            'idle_minutes' => array_sum(array_map(fn (ResolvedShift $r) => $r->result->idleMinutes, $shifts)),
            'regular_ends_at' => Time::iso($current?->result->regularEndsAt),
            'idle_periods' => $idle,
            'shifts' => array_map(
                fn (ResolvedShift $r) => $r->toArray(isset($requests[$r->shift->id]) ? $requests[$r->shift->id]->status->value : null),
                $shifts,
            ),
            'web_clock_in_enabled' => $this->webClock->enabled() && $user->hasPermission(Permission::ClockIn),
            'this_browser_device_id' => $browserDeviceId,
            'open_shift' => $open !== null ? $this->openShift($open, $onThisBrowser) : null,
            'undo_until' => $onThisBrowser && $undoUntil->greaterThan($now) ? Time::iso($undoUntil) : null,
            'prompt_deadline_at' => Time::iso($open?->result->promptDeadlineAt),
            'presence_check' => $open !== null ? $this->presenceCheck($open, $now) : null,
            'reports_due' => array_values(array_map(
                fn (ResolvedShift $r) => $r->reportDueArray(),
                array_filter($all, fn (ResolvedShift $r) => $r->result->reportDue()),
            )),
            'late_claims' => array_values(array_map(
                fn (ResolvedShift $r) => $r->lateClaimArray(),
                array_filter($all, fn (ResolvedShift $r) => $r->result->claimableUntil !== null),
            )),
            'rules' => [
                'undo_seconds' => ShiftRules::CANCEL_WINDOW_SECONDS,
                'prompt_repeat_minutes' => $this->settings->int('attendance.prompt_repeat_minutes'),
                'presence_answer_minutes' => $this->settings->int('attendance.overtime_idle_answer_minutes'),
                'presence_check_minutes' => $this->settings->int('attendance.overtime_idle_check_minutes'),
                'resume_window_minutes' => $this->settings->int('attendance.resume_window_minutes'),
                'interrupted_after_minutes' => intdiv(ShiftRules::fromSettings($this->settings)->gapThresholdMs(), 60_000),
                'heartbeat_seconds' => $this->settings->int('sync.heartbeat_local_seconds'),
                'reason_min_length' => 10,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function openShift(ResolvedShift $open, bool $onThisBrowser): array
    {
        $r = $open->result;
        $resumeUntil = null;

        if ($r->status === ShiftStatus::Interrupted) {
            $gap = $r->interruptions[array_key_last($r->interruptions)] ?? null;
            $resumeUntil = $gap !== null && $gap[1] === null
                ? $gap[0]->addMinutes($this->settings->int('attendance.resume_window_minutes'))
                : null;
        }

        return [
            'id' => $open->shift->id,
            'status' => $r->status->value,
            'device_id' => $r->deviceId,
            'device_hostname' => Device::query()->whereKey($r->deviceId)->value('hostname'),
            'is_web' => ShiftRules::isWebDevice($r->deviceId),
            'on_this_browser' => $onThisBrowser,
            'clock_in_at' => Time::iso($r->clockInAt),
            'regular_ends_at' => Time::iso($r->regularEndsAt),
            'prompt_deadline_at' => Time::iso($r->promptDeadlineAt),
            'last_seen_at' => Time::iso($r->lastSeenAt),
            'resume_until' => Time::iso($resumeUntil),
            'overtime' => $r->overtime === null ? null : [
                'started_at' => Time::iso($r->overtime->startedAt),
                'minutes' => $r->overtime->minutes,
                'reason' => $r->overtime->reason,
            ],
        ];
    }

    /**
     * 3.11: "Masih lembur?" for overtime running in a browser. Until the check is due only next_check_at is set.
     *
     * @return array{next_check_at: string|null, check_shown_at: string|null, answer_deadline_at: string|null}|null
     */
    private function presenceCheck(ResolvedShift $open, CarbonImmutable $now): ?array
    {
        $checkAt = $open->result->webPresenceCheckAt;

        if ($checkAt === null) {
            return null;
        }

        $due = ! $now->lessThan($checkAt);

        return [
            'next_check_at' => Time::iso($checkAt),
            'check_shown_at' => $due ? Time::iso($checkAt) : null,
            'answer_deadline_at' => $due ? Time::iso($open->result->webPresenceAnswerBy) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function idlePeriod(ResolvedShift $resolved, IdlePeriodResult $period): array
    {
        return [
            'shift_id' => $resolved->shift->id,
            'started_at' => Time::iso($period->startedAt),
            'ended_at' => Time::iso($period->endedAt),
            'minutes' => $period->minutes,
            'tag' => $period->tag?->value,
            'note' => $period->note,
        ];
    }
}
