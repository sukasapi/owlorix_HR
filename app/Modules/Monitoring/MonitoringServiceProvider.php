<?php

namespace App\Modules\Monitoring;

use App\Modules\Attendance\Support\Time;
use App\Modules\Monitoring\Console\ArchiveAppUsageCommand;
use App\Modules\Monitoring\Console\PruneAccessLogsCommand;
use App\Modules\Monitoring\Listeners\RecordAuthEvents;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class MonitoringServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(Login::class, [RecordAuthEvents::class, 'login']);
        Event::listen(Failed::class, [RecordAuthEvents::class, 'failed']);
        Event::listen(Logout::class, [RecordAuthEvents::class, 'logout']);
        Event::listen(Lockout::class, [RecordAuthEvents::class, 'lockout']);

        if ($this->app->runningInConsole()) {
            $this->commands([PruneAccessLogsCommand::class, ArchiveAppUsageCommand::class]);
        }

        // Night in the studio; the shared hosting cron runs every 5 minutes, so the minute must be a multiple of 5
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('monitoring:prune-access-logs')->dailyAt('02:30')->timezone(Time::zone())->withoutOverlapping();
            $schedule->command('monitoring:archive-app-usage')->dailyAt('02:45')->timezone(Time::zone())->withoutOverlapping();
        });
    }
}
