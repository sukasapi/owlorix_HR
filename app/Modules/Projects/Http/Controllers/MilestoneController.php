<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Http\Requests\MilestoneRequest;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMilestone;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Milestones on the project page (docs/14 3.2). Shown to everyone who sees the project, set by project managers. */
class MilestoneController extends Controller
{
    public function store(MilestoneRequest $request, Project $project, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('create', [ProjectMilestone::class, $project]);

        $data = $request->validated();

        $milestone = DB::transaction(function () use ($request, $project, $data, $auditor) {
            $milestone = $project->milestones()->create([
                'name' => $data['name'],
                'kind' => $data['kind'],
                'due_date' => $data['due_date'],
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'created_by' => $request->user()->id,
            ]);
            $auditor->record('milestone.created', $milestone, null, $this->snapshot($milestone));

            return $milestone;
        });

        return back()->with('status', __('projects::messages.milestone_created', ['name' => $milestone->name]));
    }

    public function update(MilestoneRequest $request, Project $project, ProjectMilestone $milestone, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('update', $milestone);

        $data = $request->validated();

        DB::transaction(function () use ($milestone, $data, $auditor) {
            $before = $this->snapshot($milestone);
            $milestone->forceFill([
                'name' => $data['name'],
                'kind' => $data['kind'],
                'due_date' => $data['due_date'],
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
            ])->save();
            $auditor->record('milestone.updated', $milestone, $before, $this->snapshot($milestone));
        });

        return back()->with('status', __('projects::messages.milestone_updated', ['name' => $milestone->name]));
    }

    public function destroy(Project $project, ProjectMilestone $milestone, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('delete', $milestone);

        DB::transaction(function () use ($milestone, $auditor) {
            $auditor->record('milestone.deleted', $milestone, $this->snapshot($milestone), null);
            $milestone->delete();
        });

        return back()->with('status', __('projects::messages.milestone_deleted', ['name' => $milestone->name]));
    }

    /** Marks the milestone done (`done` true) or opens it again (`done` false). */
    public function complete(Request $request, Project $project, ProjectMilestone $milestone, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('complete', $milestone);

        $done = $request->validate(['done' => ['required', 'boolean']])['done'];
        $done = filter_var($done, FILTER_VALIDATE_BOOLEAN);

        if ($done === ($milestone->done_at !== null)) {
            return back();
        }

        DB::transaction(function () use ($milestone, $done, $auditor) {
            $before = ['done_at' => $milestone->done_at?->toIso8601String()];
            $milestone->forceFill(['done_at' => $done ? now() : null])->save();
            $auditor->record($done ? 'milestone.completed' : 'milestone.reopened', $milestone, $before, [
                'name' => $milestone->name,
                'done_at' => $milestone->done_at?->toIso8601String(),
            ]);
        });

        return back()->with('status', __($done ? 'projects::messages.milestone_completed' : 'projects::messages.milestone_reopened', ['name' => $milestone->name]));
    }

    /** @return array<string, mixed> */
    private function snapshot(ProjectMilestone $milestone): array
    {
        return [
            'project_id' => $milestone->project_id,
            'name' => $milestone->name,
            'kind' => $milestone->kind->value,
            'due_date' => $milestone->due_date->format('Y-m-d'),
            'note' => $milestone->note,
        ];
    }
}
