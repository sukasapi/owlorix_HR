<?php

namespace App\Modules\Identity\Models;

use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Organization\Models\Team;
use App\Modules\Overtime\Models\OvertimeRequest;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'username',
        'name',
        'email',
        'employee_code',
        'password',
        'must_change_password',
        'password_changed_at',
        'locale',
        'theme',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'status' => UserStatus::class,
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('joined_at');
    }

    public function ledTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'lead_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function hasPermission(Permission $permission): bool
    {
        return $this->checkPermissionTo($permission->value);
    }

    public function hasRoleEnum(Role $role): bool
    {
        return $this->hasRole($role->value);
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = collect($words)->filter()->take(2)->map(fn (string $w) => Str::upper(Str::substr($w, 0, 1)));

        return $letters->implode('') ?: Str::upper(Str::substr($this->username, 0, 2));
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', UserStatus::Active);
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'devices_users')->withPivot('last_online_sign_in_at');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function overtimeRequests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class);
    }
}
