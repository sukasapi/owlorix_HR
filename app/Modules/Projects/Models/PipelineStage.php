<?php

namespace App\Modules\Projects\Models;

use App\Modules\Projects\Enums\PipelinePhase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A step of the studio production pipeline (Storyboard, Rigging, Lighting, ...) that a task sits in. */
class PipelineStage extends Model
{
    protected $fillable = [
        'phase',
        'name',
        'sort',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'phase' => PipelinePhase::class,
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'stage_id');
    }

    /** Pipeline order: phase, then position inside the phase. */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByRaw("FIELD(phase, 'pre_production', 'production', 'post_production')")->orderBy('sort')->orderBy('id');
    }
}
