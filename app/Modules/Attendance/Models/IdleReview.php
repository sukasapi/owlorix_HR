<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lead's look at one PC diam period: checked, or a question the person has to answer (asked, then answered).
 *
 * @property int $id
 * @property int $shift_id
 * @property int $user_id
 * @property string $status
 * @property string|null $question
 * @property string|null $answer
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $asked_at
 * @property CarbonImmutable|null $answered_at
 * @property CarbonImmutable|null $checked_at
 */
class IdleReview extends Model
{
    public const CHECKED = 'checked';

    public const ASKED = 'asked';

    public const ANSWERED = 'answered';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'asked_at' => 'immutable_datetime',
            'answered_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asked_by');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
