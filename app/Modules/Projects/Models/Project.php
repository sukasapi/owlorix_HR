<?php

namespace App\Modules\Projects\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'status',
        'description',
        'budget_minutes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'budget_minutes' => 'integer',
        ];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot(['assigned_by', 'assigned_at']);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function subProjects(): HasMany
    {
        return $this->hasMany(SubProject::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(WorkActivityLog::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(ProjectLink::class);
    }
}
