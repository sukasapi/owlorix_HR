<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Calculation\ShiftEvent;
use App\Modules\Attendance\Enums\EventType;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only: only the shift link changes after insert, rows are never deleted. */
class AttendanceEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'occurred_at' => 'immutable_datetime',
            'occurred_at_device' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'uptime_ms' => 'integer',
            'server_offset_ms' => 'integer',
            'offline' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function toShiftEvent(): ShiftEvent
    {
        return new ShiftEvent(
            id: $this->id,
            type: $this->type,
            occurredAt: $this->occurred_at->utc(),
            occurredAtDevice: $this->occurred_at_device?->utc(),
            deviceId: $this->device_id,
            payload: $this->payload ?? [],
            offline: $this->offline,
        );
    }
}
