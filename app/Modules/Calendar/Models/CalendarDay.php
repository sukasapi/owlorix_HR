<?php

namespace App\Modules\Calendar\Models;

use App\Modules\Calendar\Enums\CalendarDayType;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarDay extends Model
{
    protected $fillable = ['date', 'type', 'name', 'created_by'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'type' => CalendarDayType::class,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
