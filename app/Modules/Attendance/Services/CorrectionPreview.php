<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Calculation\ShiftEvent;
use App\Modules\Attendance\Enums\CorrectionField;
use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Checks a correction against its shift and calculates the work date with it, without saving (3.10). The same
 * checks run again when a correction is applied, so a proposal made on an older state cannot slip through.
 *
 * Errors are keyed `time` (the corrected time) or `shift_id` (the shift itself).
 */
class CorrectionPreview
{
    /** A corrected time cannot make a shift longer than this */
    public const MAX_SHIFT_HOURS = 24;

    public function __construct(private readonly WorkDateCalculator $dates) {}

    /** @throws ValidationException */
    public function evaluate(Shift $shift, CorrectionField $field, CarbonImmutable $value, ?CarbonImmutable $now = null): CorrectionOutcome
    {
        $now ??= CarbonImmutable::now();
        $value = $value->utc();

        $before = $this->kept($this->dates->calculate($shift->user_id, $shift->work_date, $now));
        $current = null;

        foreach ($before as $resolved) {
            if ($resolved->shift->id === $shift->id) {
                $current = $resolved;
            }
        }

        if ($current === null) {
            throw ValidationException::withMessages(['shift_id' => 'Shift ini sudah tidak ada. Muat ulang halaman.']);
        }

        $old = CorrectionOutcome::valueOf($current->result, $field);
        $this->check($current, $field, $old, $value, $now);

        // A clock-in moved earlier is also the next clock-in of the shift before it on the same date
        $overrides = [];

        if ($field === CorrectionField::ClockIn) {
            $previous = $this->neighbour($shift, before: true);

            if ($previous !== null && $previous->work_date === $shift->work_date) {
                $overrides[$previous->id] = $value;
            }
        }

        $event = new ShiftEvent(
            id: 'preview',
            type: EventType::CorrectionApplied,
            occurredAt: $now->utc(),
            occurredAtDevice: $now->utc(),
            payload: ['field' => $field->value, 'value' => Time::iso($value)],
        );

        $after = $this->kept($this->dates->calculate($shift->user_id, $shift->work_date, $now, $overrides, keepSavedRegular: false, extraEvents: [$shift->id => [$event]]));

        $outcome = new CorrectionOutcome(
            shiftId: $shift->id,
            workDate: $shift->work_date,
            field: $field,
            oldValue: $old,
            newValue: $value,
            before: $before,
            after: $after,
            closedMonth: substr($shift->work_date, 0, 7) < substr(Time::workDate($now), 0, 7),
            overtimeReset: $this->resetsDecision($shift, $after),
        );

        $applied = $outcome->target() !== null ? CorrectionOutcome::valueOf($outcome->target()->result, $field) : null;

        if (! $this->sameMinute($applied, $value)) {
            throw ValidationException::withMessages(['time' => 'Koreksi ini tidak bisa diterapkan pada shift ini. Periksa jamnya lagi.']);
        }

        return $outcome;
    }

    /** @throws ValidationException */
    private function check(ResolvedShift $current, CorrectionField $field, ?CarbonImmutable $old, CarbonImmutable $value, CarbonImmutable $now): void
    {
        $r = $current->result;
        $shift = $current->shift;
        $ended = ! $r->isLive();

        if ($value->greaterThan($now)) {
            $this->refuse('Jam koreksi tidak boleh lebih dari sekarang.');
        }

        if ($this->sameMinute($old, $value)) {
            $this->refuse(sprintf('%s sudah tercatat %s.', ucfirst($field->label()), $this->clock($old)));
        }

        // The calculator lays corrections over an ended shift only; a running shift keeps what its device shows
        if (! $ended) {
            $this->refuse('Shift ini masih berjalan. Koreksi bisa dibuat setelah shift selesai.');
        }

        match ($field) {
            CorrectionField::ClockIn => $this->checkClockIn($shift, $r->clockOutAt, $value),
            CorrectionField::ClockOut => $this->checkClockOut($shift, $r->clockInAt, $value),
            CorrectionField::OvertimeStart => $this->checkOvertimeStart($current, $value),
            CorrectionField::OvertimeEnd => $this->checkOvertimeEnd($current, $value),
        };
    }

    private function checkClockIn(Shift $shift, ?CarbonImmutable $clockOut, CarbonImmutable $value): void
    {
        if (Time::workDate($value) !== $shift->work_date) {
            $this->refuse('Jam masuk harus tetap di tanggal kerja shift ini. Shift di tanggal lain belum bisa dibuat lewat koreksi.');
        }

        if ($clockOut !== null && ! $value->lessThan($clockOut)) {
            $this->refuse(sprintf('Jam masuk harus sebelum jam pulang (%s).', $this->clock($clockOut)));
        }

        if ($clockOut !== null && $value->diffInMinutes($clockOut) > self::MAX_SHIFT_HOURS * 60) {
            $this->refuse(sprintf('Satu shift paling panjang %d jam.', self::MAX_SHIFT_HOURS));
        }

        $previous = $this->neighbour($shift, before: true);

        if ($previous?->clock_out_at !== null && $value->lessThan($previous->clock_out_at)) {
            $this->refuse(sprintf('Jam masuk tidak boleh sebelum shift sebelumnya selesai (%s).', $this->clock($previous->clock_out_at)));
        }
    }

    private function checkClockOut(Shift $shift, CarbonImmutable $clockIn, CarbonImmutable $value): void
    {
        if (! $value->greaterThan($clockIn)) {
            $this->refuse(sprintf('Jam pulang harus setelah jam masuk (%s).', $this->clock($clockIn)));
        }

        if ($clockIn->diffInMinutes($value) > self::MAX_SHIFT_HOURS * 60) {
            $this->refuse(sprintf('Satu shift paling panjang %d jam.', self::MAX_SHIFT_HOURS));
        }

        $next = $this->neighbour($shift, before: false);

        if ($next !== null && $value->greaterThan($next->clock_in_at)) {
            $this->refuse(sprintf('Jam pulang tidak boleh melewati absen masuk berikutnya (%s).', $this->dateClock($next->clock_in_at)));
        }
    }

    private function checkOvertimeStart(ResolvedShift $current, CarbonImmutable $value): void
    {
        $r = $current->result;

        if (! $current->isWorkday) {
            $this->refuse('Tanggal ini bukan hari kerja, jadi seluruh shift sudah lembur sejak jam masuk. Koreksi jam masuk kalau perlu.');
        }

        if ($r->clockOutAt !== null && ! $value->lessThan($r->clockOutAt)) {
            $this->refuse(sprintf('Mulai lembur harus sebelum jam pulang (%s). Kalau shift berakhir lebih lambat, koreksi jam pulang dulu.', $this->clock($r->clockOutAt)));
        }

        // 3.4.1: overtime runs from the 8-hour mark; with the limit already reached, the mark is the clock-in
        $mark = $r->regularEndsAt ?? $r->clockInAt;

        if ($value->lessThan($mark)) {
            $this->refuse(sprintf('Lembur baru bisa mulai setelah jam reguler hari ini penuh, pukul %s.', $this->clock($mark)));
        }
    }

    private function checkOvertimeEnd(ResolvedShift $current, CarbonImmutable $value): void
    {
        $r = $current->result;

        if ($r->overtime === null) {
            $this->refuse('Shift ini tidak punya lembur. Koreksi mulai lembur dulu.');
        }

        if (! $value->greaterThan($r->overtime->startedAt)) {
            $this->refuse(sprintf('Selesai lembur harus setelah mulai lembur (%s).', $this->clock($r->overtime->startedAt)));
        }

        if ($r->clockOutAt !== null && $value->greaterThan($r->clockOutAt)) {
            $this->refuse(sprintf('Selesai lembur tidak boleh setelah jam pulang (%s). Koreksi jam pulang dulu.', $this->clock($r->clockOutAt)));
        }
    }

    /**
     * I12: a decided overtime request goes back to pending when the correction changes its minutes.
     *
     * @param  list<ResolvedShift>  $after
     */
    private function resetsDecision(Shift $shift, array $after): bool
    {
        $request = OvertimeRequest::query()->where('shift_id', $shift->id)->first(['id', 'status', 'minutes']);

        if ($request === null || $request->status === OvertimeStatus::Pending) {
            return false;
        }

        foreach ($after as $resolved) {
            if ($resolved->shift->id === $shift->id) {
                return $resolved->result->overtimeMinutes !== $request->minutes;
            }
        }

        return $request->minutes !== 0;
    }

    /** The person's shift just before or after this one, on any date. */
    public function neighbour(Shift $shift, bool $before): ?Shift
    {
        return Shift::query()
            ->where('user_id', $shift->user_id)
            ->whereKeyNot($shift->id)
            ->where('clock_in_at', $before ? '<' : '>', Time::db($shift->clock_in_at))
            ->orderBy('clock_in_at', $before ? 'desc' : 'asc')
            ->orderBy('id', $before ? 'desc' : 'asc')
            ->first();
    }

    /**
     * @param  list<ResolvedShift>  $list
     * @return list<ResolvedShift>
     */
    private function kept(array $list): array
    {
        return array_values(array_filter($list, fn (ResolvedShift $r) => ! $r->result->cancelled));
    }

    private function sameMinute(?CarbonImmutable $a, ?CarbonImmutable $b): bool
    {
        return $a !== null && $b !== null && $a->utc()->format('Y-m-d H:i') === $b->utc()->format('Y-m-d H:i');
    }

    private function clock(?CarbonImmutable $time): string
    {
        return $time?->setTimezone(Time::zone())->format('H.i') ?? '';
    }

    private function dateClock(CarbonImmutable $time): string
    {
        return $time->setTimezone(Time::zone())->locale('id')->translatedFormat('j M').' '.$this->clock($time);
    }

    /** @throws ValidationException */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['time' => $message]);
    }
}
