<?php

namespace App\Modules\Monitoring\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Monitoring\Enums\AccessEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One access: a sign-in, sign-out, page view, action, refusal, or download (docs/14 2). Written by AccessRecorder. */
class AccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event' => AccessEvent::class,
            'status' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
