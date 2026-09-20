<?php

namespace App\Modules\Calendar;

use App\Modules\Calendar\Listeners\ApplyTeamChangesToOpenedWorkdays;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Calendar\Models\WorkWeekDay;
use App\Modules\Calendar\Policies\CalendarDayPolicy;
use App\Modules\Calendar\Policies\OpenedWorkdayPolicy;
use App\Modules\Calendar\Policies\WorkWeekDayPolicy;
use App\Modules\Organization\Events\TeamMembersChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Server messages live in the module (`__('calendar::messages.key')`) so the module owns its strings.
 */
class CalendarServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/lang', 'calendar');

        Gate::policy(CalendarDay::class, CalendarDayPolicy::class);
        Gate::policy(OpenedWorkday::class, OpenedWorkdayPolicy::class);
        Gate::policy(WorkWeekDay::class, WorkWeekDayPolicy::class);

        Event::listen(TeamMembersChanged::class, ApplyTeamChangesToOpenedWorkdays::class);
    }
}
