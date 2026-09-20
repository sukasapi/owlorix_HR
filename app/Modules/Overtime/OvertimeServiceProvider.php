<?php

namespace App\Modules\Overtime;

use App\Modules\Attendance\Events\ShiftRecalculated;
use App\Modules\Overtime\Listeners\SyncOvertimeRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class OvertimeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(ShiftRecalculated::class, SyncOvertimeRequest::class);
    }
}
