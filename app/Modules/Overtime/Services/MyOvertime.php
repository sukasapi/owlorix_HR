<?php

namespace App\Modules\Overtime\Services;

use App\Modules\Attendance\Enums\OvertimeEndReason;
use App\Modules\Attendance\Services\ResolvedShift;
use App\Modules\Attendance\Services\ShiftStateResolver;
use App\Modules\Attendance\Services\WebClock;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Props for the web "Lembur" page: the person's own overtime requests, newest work date first, and the shifts the
 * rules ended automatically that can still get a late overtime claim (3.3.6, 3.5.5). Claims are filed on Hari ini or
 * in the desktop app (3.11.7); this page only points there. Queries do not grow with the page size.
 */
class MyOvertime
{
    public const PER_PAGE = 15;

    public function __construct(
        private readonly ShiftStateResolver $resolver,
        private readonly OvertimeHistory $history,
        private readonly WebClock $webClock,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array{status: string, bulan: string|null}  $filters  status is `all` or an OvertimeStatus value
     * @return array<string, mixed>
     */
    public function build(User $user, array $filters, CarbonImmutable $now): array
    {
        $requests = $this->own($user)
            ->when($filters['status'] !== 'all', fn (Builder $q) => $q->where('overtime_requests.status', $filters['status']))
            ->when($filters['bulan'] !== null, function (Builder $q) use ($filters) {
                $month = CarbonImmutable::createFromFormat('!Y-m', $filters['bulan'], 'UTC');
                $q->whereBetween('shifts.work_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()]);
            })
            ->select('overtime_requests.*', 'shifts.work_date as work_date')
            ->with(['decisions.decider:id,name', 'shift'])
            ->orderByDesc('shifts.work_date')
            ->orderByDesc('overtime_requests.started_at')
            ->orderByDesc('overtime_requests.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Work dates read for this moment, once: saved rows of running overtime lag the clock, and the dates that may
        // need an answer hold the late claims
        $running = $requests->getCollection()
            ->filter(fn (OvertimeRequest $request) => $request->ended_at === null)
            ->map(fn (OvertimeRequest $request) => (string) $request->getAttribute('work_date'))
            ->all();
        $byDate = $this->resolver->workDates($user->id, array_unique([...$running, ...$this->resolver->attentionDates($user, $now)]), $now);

        $requests->through(fn (OvertimeRequest $request) => $this->item($request, $byDate));

        $months = $this->own($user)
            ->distinct()
            ->orderByDesc('shifts.work_date')
            ->pluck('shifts.work_date')
            ->map(fn ($date) => substr((string) $date, 0, 7))
            ->unique()
            ->values()
            ->all();

        $options = $months;

        // A month picked through the address bar stays selectable even when it has no overtime
        if ($filters['bulan'] !== null && ! in_array($filters['bulan'], $options, true)) {
            $options[] = $filters['bulan'];
            rsort($options);
        }

        return [
            'filters' => $filters,
            'months' => $options,
            'has_requests' => $months !== [],
            'requests' => $requests,
            'late_claims' => $this->lateClaims($byDate),
            // Reports due and late claims can also be written on Hari ini while web clock-in is on (3.11.7)
            'web_clock_in_enabled' => $this->webClock->enabled(),
            // Rule values the page quotes, so its sentences follow the settings
            'rules' => [
                'regular_limit_minutes' => $this->settings->int('attendance.regular_limit_minutes'),
                'prompt_auto_close_minutes' => $this->settings->int('attendance.prompt_auto_close_minutes'),
                'overtime_idle_answer_minutes' => $this->settings->int('attendance.overtime_idle_answer_minutes'),
                'late_claim_hours' => $this->settings->int('overtime.late_claim_hours'),
            ],
        ];
    }

    private function own(User $user): Builder
    {
        return OvertimeRequest::query()
            ->join('shifts', 'shifts.id', '=', 'overtime_requests.shift_id')
            ->where('overtime_requests.user_id', $user->id)
            ->where('shifts.user_id', $user->id);
    }

    /**
     * @param  array<string, list<ResolvedShift>>  $byDate
     * @return array<string, mixed>
     */
    private function item(OvertimeRequest $request, array $byDate): array
    {
        $startedAt = $request->started_at;
        $endedAt = $request->ended_at;
        $minutes = $request->minutes;
        $workReport = $request->work_report;
        $running = $endedAt === null;

        // Saved rows of running overtime lag the clock; the time rules may also have ended it already (3.5.3)
        if ($running && $request->shift !== null) {
            $resolved = collect($byDate[$request->shift->work_date] ?? [])->first(fn (ResolvedShift $r) => $r->shift->is($request->shift));
            $overtime = $resolved?->result->overtime;

            if ($overtime !== null) {
                $startedAt = $overtime->startedAt;
                $endedAt = $overtime->endedAt;
                $minutes = $overtime->minutes;
                $workReport = $overtime->workReport;
                $running = $endedAt === null;
            }
        }

        return [
            'id' => $request->id,
            'shift_id' => $request->shift_id,
            'work_date' => (string) $request->getAttribute('work_date'),
            'started_at' => Time::iso($startedAt),
            'ended_at' => Time::iso($endedAt),
            'minutes' => $minutes,
            'reason' => $request->reason !== '' ? $request->reason : null,
            'work_report' => $workReport,
            'is_running' => $running,
            'report_due' => ! $running && $workReport === null,
            'is_late_claim' => $request->is_late_claim,
            'status' => $request->status->value,
            'was_reset' => $request->status === OvertimeStatus::Pending && $request->decisions->isNotEmpty(),
            'decisions' => $this->history->decisions($request),
        ];
    }

    /**
     * @param  array<string, list<ResolvedShift>>  $byDate
     * @return list<array<string, mixed>>
     */
    private function lateClaims(array $byDate): array
    {
        $claims = [];

        foreach ($byDate as $shifts) {
            foreach ($shifts as $resolved) {
                if ($resolved->result->claimableUntil !== null) {
                    $claims[] = $this->claim($resolved);
                }
            }
        }

        usort($claims, fn (array $a, array $b) => strcmp($a['claimable_until'], $b['claimable_until']));

        return $claims;
    }

    /** @return array<string, mixed> */
    private function claim(ResolvedShift $resolved): array
    {
        $r = $resolved->result;

        return [
            'shift_id' => $resolved->shift->id,
            'work_date' => $resolved->shift->work_date,
            'clock_in_at' => Time::iso($r->clockInAt),
            // Where the rules ended the shift: the 8-hour mark (3.3.5) or the start of the quiet period (3.5.3)
            'auto_ended_at' => Time::iso($r->clockOutAt),
            'cause' => $r->overtimeEndReason === OvertimeEndReason::PresenceCheckNoAnswer ? 'presence_check' : 'prompt',
            'claimable_until' => Time::iso($r->claimableUntil),
            'latest_end_at' => Time::iso($r->latestClaimEndAt),
        ];
    }
}
