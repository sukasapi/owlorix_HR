<?php

namespace App\Modules\Identity\Models;

use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A studio PC running the desktop app. The id is the machine based id the app sends. Device tokens are
 * Sanctum tokens named with this id, so a revoked device refuses every token issued on it.
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['id', 'hostname', 'app_version', 'last_seen_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): DeviceFactory
    {
        return DeviceFactory::new();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'devices_users')->withPivot('last_online_sign_in_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
