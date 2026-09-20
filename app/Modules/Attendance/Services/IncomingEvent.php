<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\EventType;
use Carbon\CarbonImmutable;

/** A synced event after time correction, before it is stored. */
final readonly class IncomingEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public EventType $type,
        public CarbonImmutable $occurredAt,
        public CarbonImmutable $occurredAtDevice,
        public string $bootId,
        public int $uptimeMs,
        public int $serverOffsetMs,
        public bool $offline,
        public array $payload,
        public int $index,
    ) {}

    public function ms(): int
    {
        return $this->occurredAt->getTimestampMs();
    }
}
