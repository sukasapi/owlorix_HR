<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Calendar\Services\WorkdayResolver;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Throwable;
use TypeError;
use UnexpectedValueException;
use ValueError;

/**
 * Stores synced events and recalculates the shifts they touch (3.8). The person always comes from the device
 * token. Events are handled in occurred order; an id this person already sent is a no-op (3.8.2). Heartbeats are
 * not stored: they move shifts.last_seen_at.
 *
 * One bad event must never block the PC outbox: if the batch fails on something tied to its data (a constraint
 * or a value the engine cannot handle), it is replayed one event at a time and only the failing events are
 * rejected. Infrastructure errors (database down) still fail the whole request so the PC retries.
 */
class EventIngestor
{
    public function __construct(
        private readonly EventTimeCorrector $corrector,
        private readonly ShiftRecalculator $recalculator,
        private readonly WorkdayResolver $calendar,
        private readonly Settings $settings,
    ) {}

    /** @param array<int, array<string, mixed>> $events validated events keyed by their position in the batch */
    public function ingest(User $user, Device $device, array $events, ?CarbonImmutable $now = null): IngestResult
    {
        $now ??= CarbonImmutable::now();

        try {
            return DB::transaction(fn () => $this->process($user, $device, $events, $now, isolate: false));
        } catch (Throwable $e) {
            if (! self::isEventFault($e)) {
                throw $e;
            }

            report($e);

            return DB::transaction(fn () => $this->process($user, $device, $events, $now, isolate: true));
        }
    }

    /** A failure caused by the data of an event, which retrying will not fix. */
    public static function isEventFault(Throwable $e): bool
    {
        if ($e instanceof QueryException) {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());

            return str_starts_with($state, '22') || str_starts_with($state, '23') || $e instanceof UniqueConstraintViolationException;
        }

        return $e instanceof ValueError
            || $e instanceof TypeError
            || $e instanceof InvalidArgumentException
            || $e instanceof UnexpectedValueException
            || $e instanceof DomainException
            || $e instanceof JsonException;
    }

    /** @param array<int, array<string, mixed>> $events */
    private function process(User $user, Device $device, array $events, CarbonImmutable $now, bool $isolate): IngestResult
    {
        // One sync per person at a time, so two PCs cannot open two shifts together
        User::withTrashed()->whereKey($user->getKey())->lockForUpdate()->first();

        $incoming = $this->corrector->correct($device->id, $events, $now);
        usort($incoming, fn (IncomingEvent $a, IncomingEvent $b) => [$a->ms(), $a->index] <=> [$b->ms(), $b->index]);

        /** @var array<string, int> $owners event id => user id */
        $owners = AttendanceEvent::query()
            ->whereIn('id', array_map(fn (IncomingEvent $e) => $e->id, $incoming))
            ->pluck('user_id', 'id')
            ->all();

        $accepted = [];
        $duplicates = [];
        $rejected = [];
        /** @var array<int, true> $dirty */
        $dirty = [];

        foreach ($incoming as $event) {
            if ($event->type === EventType::Heartbeat) {
                $this->heartbeat($user, $event, $dirty);
                $accepted[] = $event->id;

                continue;
            }

            if (isset($owners[$event->id])) {
                // 3.8.2: a resend is a no-op, but an id another person already used is never silently dropped
                if ((int) $owners[$event->id] === $user->id) {
                    $duplicates[] = $event->id;
                } else {
                    $rejected[] = ['id' => $event->id, 'index' => $event->index, 'code' => 'id_conflict'];
                }

                continue;
            }

            if (! $isolate) {
                $this->store($user, $device, $event, $now, $dirty);
                $owners[$event->id] = $user->id;
                $accepted[] = $event->id;

                continue;
            }

            try {
                DB::transaction(function () use ($user, $device, $event, $now) {
                    $own = [];
                    $this->store($user, $device, $event, $now, $own);
                    $this->recalculator->recalculateShifts(array_keys($own), $now);
                });

                $owners[$event->id] = $user->id;
                $accepted[] = $event->id;
            } catch (Throwable $e) {
                if (! self::isEventFault($e)) {
                    throw $e;
                }

                report($e);
                $rejected[] = ['id' => $event->id, 'index' => $event->index, 'code' => 'unprocessable'];
            }
        }

        $device->forceFill(['last_seen_at' => $now])->save();

        if (! $isolate) {
            $this->recalculator->recalculateShifts(array_keys($dirty), $now);
        } else {
            // Only heartbeats are left to apply here; a failure leaves the saved shift for the next sync or settle
            try {
                DB::transaction(fn () => $this->recalculator->recalculateShifts(array_keys($dirty), $now));
            } catch (Throwable $e) {
                if (! self::isEventFault($e)) {
                    throw $e;
                }

                report($e);
            }
        }

        return new IngestResult($accepted, $duplicates, $rejected);
    }

    /** @param array<int, true> $dirty */
    private function store(User $user, Device $device, IncomingEvent $event, CarbonImmutable $now, array &$dirty): void
    {
        $payload = $event->payload;

        if ($event->type === EventType::ClockIn) {
            $shift = $this->openShift($user, $event, $now, $dirty);
        } else {
            $shift = $this->targetShift($user, $event);

            if ($event->type === EventType::ShiftResumed && $shift !== null) {
                // The last heartbeat before the resume marks where a crash gap started (3.7.3)
                $payload['_server'] = ['last_seen_at' => Time::iso($shift->last_seen_at)];
            }
        }

        AttendanceEvent::query()->create([
            'id' => $event->id,
            'user_id' => $user->id,
            'device_id' => $device->id,
            'shift_id' => $shift?->id,
            'type' => $event->type,
            'occurred_at' => $event->occurredAt,
            'occurred_at_device' => $event->occurredAtDevice,
            'boot_id' => $event->bootId,
            'uptime_ms' => $event->uptimeMs,
            'server_offset_ms' => $event->serverOffsetMs,
            'offline' => $event->offline,
            'payload' => $payload,
            'received_at' => $now,
        ]);

        if ($shift !== null) {
            $dirty[$shift->id] = true;
        }
    }

    /**
     * 3.1.2 and 3.1.3: a clock-in opens a shift. If the person still has a shift without a clock-out, that shift is
     * first recalculated with this clock-in as its hard end, which closes it (the app sends shift_moved or
     * shift_resumed instead when it knows the shift is still running).
     *
     * @param  array<int, true>  $dirty
     */
    private function openShift(User $user, IncomingEvent $event, CarbonImmutable $now, array &$dirty): Shift
    {
        $at = $event->occurredAt;
        $previous = $this->shiftAt($user->id, $at);
        $open = Shift::query()->where('user_id', $user->id)->whereNull('clock_out_at')->first();

        if ($open !== null && $open->clock_in_at->lessThan($at)) {
            $this->recalculator->recalculateWorkDate($user->id, $open->work_date, $now, [$open->id => $at]);
        }

        // Still open means that shift started after this clock-in (an older event arriving late). This one is saved
        // closed; its recalculation ends it at the newer clock-in.
        $newerOpen = Shift::query()->where('user_id', $user->id)->whereNull('clock_out_at')->exists();
        $workDate = Time::workDate($at);

        $shift = Shift::query()->create([
            'user_id' => $user->id,
            'work_date' => $workDate,
            'is_workday' => $this->calendar->isWorkday($user, $workDate),
            'clock_in_at' => $at,
            'last_seen_at' => $at,
            'clock_out_at' => $newerOpen ? $at : null,
            'status' => $newerOpen ? ShiftStatus::Closed : ShiftStatus::Open,
            'regular_limit_minutes' => $this->settings->int('attendance.regular_limit_minutes'),
            'flags' => [],
        ]);

        $next = Shift::query()
            ->where('user_id', $user->id)
            ->whereKeyNot($shift->id)
            ->where('clock_in_at', '>', Time::db($at))
            ->min('clock_in_at');

        // Events after this clock-in that were linked to the earlier shift belong to this one now
        AttendanceEvent::query()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $previous !== null
                ? $q->where('shift_id', $previous->id)->orWhereNull('shift_id')
                : $q->whereNull('shift_id'))
            ->whereNotIn('type', [EventType::ClockIn->value, EventType::ClockInCancelled->value])
            ->whereNull('payload->shift_id')
            ->where('occurred_at', '>=', Time::db($at))
            ->when($next !== null, fn ($q) => $q->where('occurred_at', '<', $next))
            ->update(['shift_id' => $shift->id]);

        if ($previous !== null && Shift::query()->whereKey($previous->id)->exists()) {
            $dirty[$previous->id] = true;
        }

        return $shift;
    }

    /** An event goes to the shift named in its payload (a report written later), else to the shift running then. */
    private function targetShift(User $user, IncomingEvent $event): ?Shift
    {
        $id = $event->payload['shift_id'] ?? null;

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $shift = Shift::query()->where('user_id', $user->id)->find((int) $id);

            if ($shift !== null) {
                return $shift;
            }
        }

        return $this->shiftAt($user->id, $event->occurredAt);
    }

    private function shiftAt(int $userId, CarbonImmutable $at): ?Shift
    {
        return Shift::query()
            ->where('user_id', $userId)
            ->where('clock_in_at', '<=', Time::db($at))
            ->orderByDesc('clock_in_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @param array<int, true> $dirty */
    private function heartbeat(User $user, IncomingEvent $event, array &$dirty): void
    {
        $shift = $this->shiftAt($user->id, $event->occurredAt);

        if ($shift === null || ! $shift->last_seen_at->lessThan($event->occurredAt)) {
            return;
        }

        Shift::query()->whereKey($shift->id)->update(['last_seen_at' => Time::db($event->occurredAt)]);

        // A shift saved as closed for missing heartbeats comes back when late heartbeats arrive (offline PC)
        if ($shift->clock_out_at !== null) {
            $dirty[$shift->id] = true;
        }
    }
}
