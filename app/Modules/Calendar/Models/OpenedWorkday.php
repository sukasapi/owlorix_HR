<?php

namespace App\Modules\Calendar\Models;

use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OpenedWorkday extends Model
{
    use SoftDeletes;

    protected $fillable = ['date', 'scope_type', 'scope_id', 'opened_by', 'note'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'scope_type' => OpenedScope::class,
        ];
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'scope_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scope_id');
    }
}
