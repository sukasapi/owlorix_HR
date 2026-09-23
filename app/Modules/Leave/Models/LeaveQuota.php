<?php

namespace App\Modules\Leave\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's annual-leave days for one year. Without a row the setting `leave.annual_quota_days` applies. */
class LeaveQuota extends Model
{
    protected $fillable = ['user_id', 'year', 'days'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'days' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
