<?php

namespace App\Modules\Monitoring\Models;

use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch of time one application window was in front on a studio PC, while the person was clocked in
 * (Aktivitas detail). The same columns live in `app_usage_archive` once a row is older than the active period.
 *
 * @property int $id
 * @property int $user_id
 * @property string $app_name
 * @property string|null $window_title
 * @property bool $is_browser
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $ended_at
 * @property int $seconds
 */
class AppUsageSession extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'app_usage_sessions';

    protected $fillable = [
        'client_id', 'user_id', 'shift_id', 'device_id', 'app_name', 'exe', 'window_title', 'is_browser', 'started_at', 'ended_at', 'seconds',
    ];

    protected function casts(): array
    {
        return [
            'is_browser' => 'boolean',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'seconds' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
