<?php

namespace App\Modules\Leave;

use App\Modules\Calendar\Events\CalendarDatesChanged;
use App\Modules\Leave\Listeners\RecountLeaveDays;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Server messages live in the module (`__('leave::messages.key')`). A calendar change recounts the workdays of
 * open and approved requests, so a holiday added later gives the day back.
 */
class LeaveServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/lang', 'leave');

        Event::listen(CalendarDatesChanged::class, RecountLeaveDays::class);
    }
}
