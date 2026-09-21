<?php

namespace App\Modules\Projects;

use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\WorkActivityLog;
use App\Modules\Projects\Policies\ProjectPolicy;
use App\Modules\Projects\Policies\WorkActivityLogPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ProjectsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/lang', 'projects');

        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(WorkActivityLog::class, WorkActivityLogPolicy::class);
    }
}
