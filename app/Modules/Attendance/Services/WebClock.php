<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Web clock-in (3.11). A browser records the same events as the desktop app and they go through the same pipeline
 * and calculator. The server writes each event at its own time, online, so a browser clock can never be wrong.
 *
 * Errors carry a stable code as the message (`clock` => `moved_elsewhere`); the page shows the translated text.
 */
class WebClock
{
    public function __construct(
        private readonly EventIngestor $ingestor,
        private readonly ShiftStateResolver $resolver,
        private readonly Settings $settings,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('attendance.web_clock_in');
    }

    /**
     * Runs a check and the events it allows while holding the person's row, the same lock the sync API takes, so a
     * double click or a PC syncing at the same moment cannot act on a shift that just changed.
     *
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     */
    public function exclusively(User $user, Closure $action): mixed
    {
        return DB::transaction(function () use ($user, $action) {
            User::withTrashed()->whereKey($user->getKey())->lockForUpdate()->first();

            return $action();
        });
    }

    /** @param array<string, mixed> $payload */
    public function record(User $user, Device $device, EventType $type, array $payload = [], ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        $result = $this->ingestor->ingest($user, $device, [[
            'id' => (string) Str::uuid7(),
            'type' => $type->value,
            'occurred_at_device' => $now->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'boot_id' => 'web',
            'uptime_ms' => 0,
            'server_offset_ms' => 0,
            'offline' => false,
            'payload' => $payload,
        ]], $now);

        if ($result->rejected !== []) {
            throw self::refuse('not_saved');
        }
    }

    public function openShift(User $user, CarbonImmutable $now): ?ResolvedShift
    {
        return $this->resolver->openShift($user, $now);
    }

    /**
     * The running shift of this browser, ready for an action. A shift running on another device is refused: it moves
     * here only through the move question (3.1.3). A shift interrupted because this browser stopped sending
     * heartbeats continues first (shift_resumed), so the gap is recorded: any action on the browser counts as the
     * person being back (3.7.3).
     */
    public function shiftOnThisBrowser(User $user, Device $device, CarbonImmutable $now, string $whenNone = 'no_open_shift'): ResolvedShift
    {
        $open = $this->openShift($user, $now);

        if ($open === null) {
            throw self::refuse($whenNone);
        }

        if ($open->result->deviceId !== $device->id) {
            throw self::refuse('moved_elsewhere');
        }

        if ($open->result->status === ShiftStatus::Interrupted) {
            $this->record($user, $device, EventType::ShiftResumed, [], $now);
            $open = $this->openShift($user, $now) ?? throw self::refuse($whenNone);
        }

        return $open;
    }

    public static function refuse(string $code, string $field = 'clock'): ValidationException
    {
        return ValidationException::withMessages([$field => $code]);
    }
}
