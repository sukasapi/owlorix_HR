<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Http\Requests\StoreActivityLogRequest;
use App\Modules\Projects\Http\Requests\UpdateActivityLogRequest;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\WorkActivityLog;
use App\Modules\Shared\Audit\Auditor;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WorkActivityLog::class);

        $user = $request->user();
        $prefillProjectId = $request->integer('project_id') ?: null;

        $assignedIds = ProjectMember::query()
            ->where('user_id', $user->id)
            ->pluck('project_id')
            ->all();

        $projects = Project::query()
            ->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::Planned->value])
            ->orderByRaw("FIELD(status, 'active', 'planned')")
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'status'])
            ->sortBy(fn (Project $p) => in_array($p->id, $assignedIds, true) ? 0 : 1)
            ->values()
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code,
                'status' => $p->status->value,
                'assigned' => in_array($p->id, $assignedIds, true),
            ]);

        $logs = WorkActivityLog::query()
            ->with('project:id,name,code')
            ->where('user_id', $user->id)
            ->orderByDesc('started_at')
            ->limit(100)
            ->get()
            ->map(fn (WorkActivityLog $log) => $this->row($log));

        return Inertia::render('projects/ActivityIndex', [
            'logs' => $logs,
            'projects' => $projects,
            'prefill_project_id' => $prefillProjectId,
        ]);
    }

    public function store(StoreActivityLogRequest $request, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('create', WorkActivityLog::class);

        $data = $request->validated();

        $log = DB::transaction(function () use ($request, $data, $auditor) {
            $log = WorkActivityLog::query()->create([
                'user_id' => $request->user()->id,
                'project_id' => $data['project_id'],
                'description' => $data['description'],
                'started_at' => Carbon::parse($data['started_at'], config('owlorix.display_timezone'))->utc(),
                'ended_at' => Carbon::parse($data['ended_at'], config('owlorix.display_timezone'))->utc(),
                'evidence_url' => $data['evidence_url'],
            ]);
            $auditor->record('activity.created', $log, null, [
                'project_id' => $log->project_id,
                'started_at' => $log->started_at->toIso8601String(),
                'ended_at' => $log->ended_at->toIso8601String(),
            ]);

            return $log;
        });

        return back()->with('status', __('projects::messages.activity_saved'));
    }

    public function update(UpdateActivityLogRequest $request, WorkActivityLog $activity, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('update', $activity);

        $data = $request->validated();

        DB::transaction(function () use ($activity, $data, $auditor) {
            $before = [
                'project_id' => $activity->project_id,
                'description' => $activity->description,
                'started_at' => $activity->started_at->toIso8601String(),
                'ended_at' => $activity->ended_at->toIso8601String(),
                'evidence_url' => $activity->evidence_url,
            ];
            $activity->forceFill([
                'project_id' => $data['project_id'],
                'description' => $data['description'],
                'started_at' => Carbon::parse($data['started_at'], config('owlorix.display_timezone'))->utc(),
                'ended_at' => Carbon::parse($data['ended_at'], config('owlorix.display_timezone'))->utc(),
                'evidence_url' => $data['evidence_url'],
            ])->save();
            $auditor->record('activity.updated', $activity, $before, [
                'project_id' => $activity->project_id,
                'description' => $activity->description,
                'started_at' => $activity->started_at->toIso8601String(),
                'ended_at' => $activity->ended_at->toIso8601String(),
                'evidence_url' => $activity->evidence_url,
            ]);
        });

        return back()->with('status', __('projects::messages.activity_saved'));
    }

    public function destroy(Request $request, WorkActivityLog $activity, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('delete', $activity);

        DB::transaction(function () use ($activity, $auditor) {
            $auditor->record('activity.deleted', $activity, [
                'project_id' => $activity->project_id,
                'started_at' => $activity->started_at->toIso8601String(),
            ], null);
            $activity->delete();
        });

        return back()->with('status', __('projects::messages.activity_deleted'));
    }

    /** @return array<string, mixed> */
    private function row(WorkActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'project_id' => $log->project_id,
            'project_name' => $log->project?->name,
            'project_code' => $log->project?->code,
            'description' => $log->description,
            'started_at' => $log->started_at->toIso8601String(),
            'ended_at' => $log->ended_at->toIso8601String(),
            'evidence_url' => $log->evidence_url,
        ];
    }
}
