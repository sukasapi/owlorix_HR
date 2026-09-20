<?php

namespace App\Modules\Overtime\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Overtime\Enums\Decision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in the decision history of an overtime request; the latest one is current. */
class OvertimeDecision extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['overtime_request_id', 'decided_by', 'decision', 'note', 'decided_at'];

    protected function casts(): array
    {
        return [
            'decision' => Decision::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OvertimeRequest::class, 'overtime_request_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }
}
