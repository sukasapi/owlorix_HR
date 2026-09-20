<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Calculation\ShiftResult;
use App\Modules\Attendance\Enums\CorrectionField;
use App\Modules\Attendance\Support\Time;
use Carbon\CarbonImmutable;

/** What a correction would change on its work date, calculated without saving anything (CorrectionPreview). */
final readonly class CorrectionOutcome
{
    /**
     * @param  list<ResolvedShift>  $before  the work date as it is resolved now
     * @param  list<ResolvedShift>  $after  the work date as it is saved once the correction is applied
     */
    public function __construct(
        public int $shiftId,
        public string $workDate,
        public CorrectionField $field,
        public ?CarbonImmutable $oldValue,
        public CarbonImmutable $newValue,
        public array $before,
        public array $after,
        public bool $closedMonth,
        public bool $overtimeReset,
    ) {}

    public static function valueOf(ShiftResult $result, CorrectionField $field): ?CarbonImmutable
    {
        return match ($field) {
            CorrectionField::ClockIn => $result->clockInAt,
            CorrectionField::ClockOut => $result->clockOutAt,
            CorrectionField::OvertimeStart => $result->overtime?->startedAt,
            CorrectionField::OvertimeEnd => $result->overtime?->endedAt,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $before = $this->byId($this->before);
        $after = $this->byId($this->after);

        $shifts = [];

        foreach ($after as $id => $resolved) {
            $shifts[] = [
                'id' => $id,
                'is_target' => $id === $this->shiftId,
                'before' => isset($before[$id]) ? $this->summary($before[$id]) : null,
                'after' => $this->summary($resolved),
            ];
        }

        return [
            'shift_id' => $this->shiftId,
            'work_date' => $this->workDate,
            'field' => $this->field->value,
            'old_value' => Time::iso($this->oldValue),
            'new_value' => Time::iso($this->newValue),
            'closed_month' => $this->closedMonth,
            'overtime_reset' => $this->overtimeReset,
            'date' => [
                'before' => $this->totals($this->before),
                'after' => $this->totals($this->after),
            ],
            'shifts' => $shifts,
        ];
    }

    public function target(): ?ResolvedShift
    {
        return $this->byId($this->after)[$this->shiftId] ?? null;
    }

    /**
     * @param  list<ResolvedShift>  $list
     * @return array<int, ResolvedShift>
     */
    private function byId(array $list): array
    {
        $out = [];

        foreach ($list as $resolved) {
            $out[$resolved->shift->id] = $resolved;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function summary(ResolvedShift $resolved): array
    {
        $r = $resolved->result;

        return [
            'status' => $r->status->value,
            'clock_in_at' => Time::iso($r->clockInAt),
            'clock_out_at' => Time::iso($r->clockOutAt),
            'overtime_started_at' => Time::iso($r->overtime?->startedAt),
            'overtime_ended_at' => Time::iso($r->overtime?->endedAt),
            'regular_minutes' => $r->regularMinutes,
            'overtime_minutes' => $r->overtimeMinutes,
        ];
    }

    /**
     * @param  list<ResolvedShift>  $list
     * @return array{regular_minutes: int, overtime_minutes: int}
     */
    private function totals(array $list): array
    {
        return [
            'regular_minutes' => array_sum(array_map(fn (ResolvedShift $r) => $r->result->regularMinutes, $list)),
            'overtime_minutes' => array_sum(array_map(fn (ResolvedShift $r) => $r->result->overtimeMinutes, $list)),
        ];
    }
}
