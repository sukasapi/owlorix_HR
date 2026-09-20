<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Calculation\ShiftEvent;
use App\Modules\Attendance\Enums\EventType;
use Carbon\CarbonImmutable;

/** A range of one person's resolved shifts, with the events they were calculated from. */
final readonly class HistoryShiftSet
{
    /**
     * @param  array<string, list<ResolvedShift>>  $shifts  by work date, only dates with shifts, cancelled clock-ins left out
     * @param  array<int, list<ShiftEvent>>  $events  by shift id
     */
    public function __construct(
        public array $shifts,
        public array $events,
    ) {}

    /** @return list<int> */
    public function shiftIds(): array
    {
        $ids = [];

        foreach ($this->shifts as $resolved) {
            foreach ($resolved as $shift) {
                $ids[] = $shift->shift->id;
            }
        }

        return $ids;
    }

    /**
     * Where the shift ran: the clock-in device, then every move to another device (3.1.3, 3.11.2) up to the end of
     * the shift. A resume from another device moves the shift the same way.
     *
     * @return list<array{at: CarbonImmutable, device_id: string}>
     */
    public function devicePath(ResolvedShift $resolved): array
    {
        $result = $resolved->result;
        $path = [['at' => $result->clockInAt, 'device_id' => $this->clockInDevice($resolved)]];
        $end = $result->clockOutAt;

        $moves = array_filter(
            $this->events[$resolved->shift->id] ?? [],
            fn (ShiftEvent $e) => in_array($e->type, [EventType::ShiftMoved, EventType::ShiftResumed], true)
                && $e->deviceId !== ''
                && $e->occurredAt->greaterThan($result->clockInAt)
                && ($end === null || $e->occurredAt->lessThanOrEqualTo($end)),
        );
        usort($moves, fn (ShiftEvent $a, ShiftEvent $b) => $a->ms() <=> $b->ms());

        foreach ($moves as $move) {
            if ($move->deviceId !== $path[array_key_last($path)]['device_id']) {
                $path[] = ['at' => $move->occurredAt, 'device_id' => $move->deviceId];
            }
        }

        return $path;
    }

    private function clockInDevice(ResolvedShift $resolved): string
    {
        foreach ($this->events[$resolved->shift->id] ?? [] as $event) {
            if ($event->type === EventType::ClockIn && $event->deviceId !== '') {
                return $event->deviceId;
            }
        }

        return $resolved->result->deviceId;
    }
}
