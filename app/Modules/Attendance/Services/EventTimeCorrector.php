<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Support\Time;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;

/**
 * Server time is the authority for stored times (3.9).
 *
 * Online event: PC clock plus the offset the app last measured against the server. It was sent as it happened, so
 * when that time is further from its arrival than attendance.clock_mismatch_seconds, the PC clock or offset is
 * wrong: the event is stored at its arrival time, and the stored PC clock time keeps the difference visible for
 * the clock_mismatch flag (3.9.4). Events that are late by design are allowed their delay: a backdated idle start
 * (the idle threshold), an automatic clock-out (the prompt answer window), and shutdown or sleep notices, which can
 * only be sent once the PC runs again.
 *
 * Offline event: time of the latest online event of the same boot plus the uptime elapsed since it; uptime does not
 * move when someone edits the Windows clock (3.9.3). Without such an event, the last known offset.
 *
 * An event can never have happened after the server received it.
 */
class EventTimeCorrector
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  array<int, array<string, mixed>>  $events  validated sync events keyed by their position in the batch
     * @return list<IncomingEvent>
     */
    public function correct(string $deviceId, array $events, CarbonImmutable $receivedAt): array
    {
        $receivedMs = $receivedAt->getTimestampMs();
        $toleranceMs = $this->settings->int('attendance.clock_mismatch_seconds') * 1000;
        /** @var array<string, list<array{uptime: int, at: int}>> $anchors */
        $anchors = [];
        $corrected = [];

        foreach ($events as $index => $raw) {
            $type = EventType::from((string) $raw['type']);
            $deviceMs = Time::parse((string) $raw['occurred_at_device'])->getTimestampMs();
            $bootId = (string) $raw['boot_id'];
            $uptime = (int) $raw['uptime_ms'];
            $offset = (int) $raw['server_offset_ms'];
            $offline = filter_var($raw['offline'], FILTER_VALIDATE_BOOLEAN);

            $at = $deviceMs + $offset;

            if ($offline) {
                $anchor = $this->anchor($deviceId, $bootId, $uptime, $anchors[$bootId] ?? []);

                if ($anchor !== null) {
                    $at = $anchor['at'] + ($uptime - $anchor['uptime']);
                }
            } elseif (($allowedDelay = $this->allowedDelayMs($type)) !== null
                && ($at > $receivedMs + $toleranceMs || $receivedMs - $at > $toleranceMs + $allowedDelay)) {
                $at = $receivedMs;
            }

            $at = min($at, $receivedMs);

            if (! $offline) {
                $anchors[$bootId][] = ['uptime' => $uptime, 'at' => $at];
            }

            $payload = is_array($raw['payload'] ?? null) ? $raw['payload'] : [];
            unset($payload['_server']);

            $corrected[] = new IncomingEvent(
                id: strtolower((string) $raw['id']),
                type: $type,
                occurredAt: Time::fromMs($at),
                occurredAtDevice: Time::fromMs($deviceMs),
                bootId: $bootId,
                uptimeMs: $uptime,
                serverOffsetMs: $offset,
                offline: $offline,
                payload: $payload,
                index: (int) $index,
            );
        }

        return $corrected;
    }

    /** How late an online event may arrive by design; null when it can arrive any time later. */
    private function allowedDelayMs(EventType $type): ?int
    {
        return match ($type) {
            EventType::PcShutdown, EventType::PcSleep => null,
            EventType::IdleStart => $this->settings->int('attendance.idle_threshold_minutes') * 60_000,
            EventType::AutoClockOut => $this->settings->int('attendance.prompt_auto_close_minutes') * 60_000,
            default => 0,
        };
    }

    /**
     * @param  list<array{uptime: int, at: int}>  $batchAnchors
     * @return array{uptime: int, at: int}|null
     */
    private function anchor(string $deviceId, string $bootId, int $uptime, array $batchAnchors): ?array
    {
        $best = null;

        foreach ($batchAnchors as $anchor) {
            if ($anchor['uptime'] <= $uptime && ($best === null || $anchor['uptime'] > $best['uptime'])) {
                $best = $anchor;
            }
        }

        $stored = AttendanceEvent::query()
            ->where('device_id', $deviceId)
            ->where('boot_id', $bootId)
            ->where('offline', false)
            ->where('uptime_ms', '<=', $uptime)
            ->orderByDesc('uptime_ms')
            ->first(['uptime_ms', 'occurred_at']);

        if ($stored !== null && ($best === null || $stored->uptime_ms > $best['uptime'])) {
            $best = ['uptime' => $stored->uptime_ms, 'at' => $stored->occurred_at->getTimestampMs()];
        }

        return $best;
    }
}
