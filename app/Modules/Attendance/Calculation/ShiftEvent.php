<?php

namespace App\Modules\Attendance\Calculation;

use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Support\Time;
use Carbon\CarbonImmutable;

final readonly class ShiftEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public EventType $type,
        public CarbonImmutable $occurredAt,
        public ?CarbonImmutable $occurredAtDevice = null,
        public string $deviceId = '',
        public array $payload = [],
        public bool $offline = false,
    ) {}

    public function ms(): int
    {
        return $this->occurredAt->getTimestampMs();
    }

    public function text(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function timeMs(string $key): ?int
    {
        $value = $this->payload[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Time::parse($value)->getTimestampMs();
        } catch (\Throwable) {
            return null;
        }
    }

    public function clockMismatch(int $thresholdSeconds): bool
    {
        return $this->occurredAtDevice !== null
            && abs($this->occurredAtDevice->getTimestampMs() - $this->ms()) > $thresholdSeconds * 1000;
    }
}
