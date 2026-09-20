<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\CorrectionField;
use App\Modules\Attendance\Enums\CorrectionStatus;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed or applied change to one time of a shift, with old value, new value, who, when and why (3.10). Values are
 * UTC ISO strings. `proposed_by` is who asked for it (the Superadmin themselves for a direct correction), `approved_by`
 * the Superadmin who applied or declined it. Applying writes a `correction_applied` event; events are never edited.
 */
class Correction extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'shift_id',
        'field',
        'old_value',
        'new_value',
        'reason',
        'proposed_by',
        'approved_by',
        'status',
        'decided_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'field' => CorrectionField::class,
            'status' => CorrectionStatus::class,
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by')->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function newValue(): CarbonImmutable
    {
        return Time::parse((string) $this->new_value);
    }

    public function isDirect(): bool
    {
        return $this->status === CorrectionStatus::Applied && $this->proposed_by === $this->approved_by;
    }
}
