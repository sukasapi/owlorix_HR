<?php

namespace App\Modules\Leave\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One leave request. Created by RequestLeave, decided by DecideLeave, cancelled by CancelLeave. */
class LeaveRequest extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = ['id'];

    protected $hidden = ['attachment_path'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'days' => 'integer',
            'status' => LeaveStatus::class,
            'decided_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    /** Requests whose dates touch the range [from, until], both Y-m-d and inclusive. */
    public function scopeOverlapping(Builder $query, string $from, string $until): void
    {
        $query->where('start_date', '<=', $until)->where('end_date', '>=', $from);
    }

    public function scopeHolding(Builder $query): void
    {
        $query->whereIn('status', LeaveStatus::holding());
    }

    public function startDate(): string
    {
        return $this->start_date->format('Y-m-d');
    }

    public function endDate(): string
    {
        return $this->end_date->format('Y-m-d');
    }

    public function year(): int
    {
        return (int) $this->start_date->format('Y');
    }
}
