<?php

namespace App\Modules\Overtime\Models;

use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Models\User;
use App\Modules\Overtime\Enums\OvertimeStatus;
use Database\Factories\OvertimeRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Kept in step with its shift by SyncOvertimeRequest; the status changes only through DecideOvertime. */
class OvertimeRequest extends Model
{
    /** @use HasFactory<OvertimeRequestFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'minutes' => 'integer',
            'is_late_claim' => 'boolean',
            'status' => OvertimeStatus::class,
        ];
    }

    protected static function newFactory(): OvertimeRequestFactory
    {
        return OvertimeRequestFactory::new();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(OvertimeDecision::class)->orderBy('decided_at')->orderBy('id');
    }

    public function latestDecision(): HasOne
    {
        return $this->hasOne(OvertimeDecision::class)->ofMany(['id' => 'max']);
    }
}
