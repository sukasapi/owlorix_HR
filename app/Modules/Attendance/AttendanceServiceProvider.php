<?php

namespace App\Modules\Attendance;

use App\Modules\Attendance\Console\SettleShiftsCommand;
use App\Modules\Attendance\Listeners\RecalculateShiftsForCalendarChange;
use App\Modules\Calendar\Events\CalendarDatesChanged;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AttendanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/lang', 'attendance');

        Event::listen(CalendarDatesChanged::class, RecalculateShiftsForCalendarChange::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SettleShiftsCommand::class]);
        }

        // Shared hosting cron runs every 5 minutes. States are already correct on read; this only saves them.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('attendance:settle')->everyFiveMinutes()->withoutOverlapping();
        });
    }
}
