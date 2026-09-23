<?php

namespace App\Modules\Projects;

use App\Modules\Projects\Models\PipelineStage;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMilestone;
use App\Modules\Projects\Models\SubProject;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\WorkActivityLog;
use App\Modules\Projects\Policies\PipelineStagePolicy;
use App\Modules\Projects\Policies\ProjectMilestonePolicy;
use App\Modules\Projects\Policies\ProjectPolicy;
use App\Modules\Projects\Policies\SubProjectPolicy;
use App\Modules\Projects\Policies\TaskPolicy;
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
        Gate::policy(SubProject::class, SubProjectPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(ProjectMilestone::class, ProjectMilestonePolicy::class);
        Gate::policy(PipelineStage::class, PipelineStagePolicy::class);
    }
}
