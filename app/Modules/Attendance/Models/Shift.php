<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\EndReason;
use App\Modules\Attendance\Enums\OvertimeEndReason;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Identity\Models\User;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Calculated from attendance events by ShiftRecalculator. `work_date` is a Y-m-d string (Asia/Jakarta).
 * Saved values can lag time rules until the next recalculation; read through ShiftStateResolver.
 */
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = ['id', 'open_user_id'];

    protected $attributes = [
        'flags' => '[]',
        'regular_before_minutes' => 0,
        'regular_minutes' => 0,
        'overtime_minutes' => 0,
        'idle_minutes' => 0,
        'interruption_minutes' => 0,
        'is_short' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_workday' => 'boolean',
            'clock_in_at' => 'immutable_datetime',
            'regular_ends_at' => 'immutable_datetime',
            'clock_out_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'status' => ShiftStatus::class,
            'end_reason' => EndReason::class,
            'overtime_end_reason' => OvertimeEndReason::class,
            'is_short' => 'boolean',
            'regular_before_minutes' => 'integer',
            'regular_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'idle_minutes' => 'integer',
            'interruption_minutes' => 'integer',
            'regular_limit_minutes' => 'integer',
            'flags' => 'array',
        ];
    }

    protected static function newFactory(): ShiftFactory
    {
        return ShiftFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    public function idlePeriods(): HasMany
    {
        return $this->hasMany(IdlePeriod::class)->orderBy('started_at');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(Correction::class);
    }
}
