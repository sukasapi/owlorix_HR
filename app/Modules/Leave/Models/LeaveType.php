<?php

namespace App\Modules\Leave\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kind of leave. Switched off instead of deleted, so old requests keep their type. */
class LeaveType extends Model
{
    protected $fillable = ['code', 'name', 'counts_against_quota', 'requires_note', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'counts_against_quota' => 'boolean',
            'requires_note' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    /** @return array{id: int, code: string, name: string, counts_against_quota: bool, requires_note: bool, is_active: bool} */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'counts_against_quota' => $this->counts_against_quota,
            'requires_note' => $this->requires_note,
            'is_active' => $this->is_active,
        ];
    }
}
