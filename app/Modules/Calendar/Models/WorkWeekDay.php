<?php

namespace App\Modules\Calendar\Models;

use Illuminate\Database\Eloquent\Model;

class WorkWeekDay extends Model
{
    protected $table = 'work_week';

    protected $primaryKey = 'weekday';

    public $incrementing = false;

    protected $fillable = ['weekday', 'is_workday', 'updated_by'];

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'is_workday' => 'boolean'];
    }
}
