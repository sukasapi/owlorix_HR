<?php

namespace App\Modules\Attendance\Calculation;

use App\Modules\Attendance\Enums\CorrectionField;
use App\Modules\Attendance\Enums\EventType;

/**
 * The corrected times of one shift, read from its `correction_applied` events (3.10). A later correction of the same
 * field replaces an earlier one. Times are UTC milliseconds; null means the field was never corrected.
 */
final readonly class ShiftCorrections
{
    public function __construct(
        public ?int $clockIn = null,
        public ?int $clockOut = null,
        public ?int $overtimeStart = null,
        public ?int $overtimeEnd = null,
    ) {}

    /** @param list<ShiftEvent> $events */
    public static function fromEvents(array $events): self
    {
        $applied = array_values(array_filter($events, fn (ShiftEvent $e) => $e->type === EventType::CorrectionApplied));
        // Two corrections applied in the same millisecond keep the order they were applied in (`sequence` per shift)
        $order = fn (ShiftEvent $e) => [$e->ms(), (int) ($e->payload['sequence'] ?? PHP_INT_MAX), $e->id];
        usort($applied, fn (ShiftEvent $a, ShiftEvent $b) => $order($a) <=> $order($b));

        $values = [];

        foreach ($applied as $event) {
            $field = CorrectionField::tryFrom((string) ($event->payload['field'] ?? ''));
            $at = $event->timeMs('value');

            if ($field !== null && $at !== null) {
                $values[$field->value] = $at;
            }
        }

        return new self(
            clockIn: $values[CorrectionField::ClockIn->value] ?? null,
            clockOut: $values[CorrectionField::ClockOut->value] ?? null,
            overtimeStart: $values[CorrectionField::OvertimeStart->value] ?? null,
            overtimeEnd: $values[CorrectionField::OvertimeEnd->value] ?? null,
        );
    }

    public function any(): bool
    {
        return $this->clockIn !== null || $this->clockOut !== null || $this->overtimeStart !== null || $this->overtimeEnd !== null;
    }
}
