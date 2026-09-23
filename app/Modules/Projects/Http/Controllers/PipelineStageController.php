<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Enums\PipelinePhase;
use App\Modules\Projects\Http\Requests\PipelineStageRequest;
use App\Modules\Projects\Models\PipelineStage;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Pipeline produksi (docs/14 3.1): the stages a task moves through, grouped by phase and ordered inside it. */
class PipelineStageController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage', PipelineStage::class);

        // Deleted tasks still point at their stage (and can be restored), so they count as using it
        $stages = PipelineStage::query()->withCount(['tasks' => fn ($q) => $q->withTrashed()])->ordered()->get();

        return Inertia::render('admin/pipeline/Index', [
            'phases' => array_map(fn (PipelinePhase $phase) => [
                'key' => $phase->value,
                'stages' => $stages->filter(fn (PipelineStage $s) => $s->phase === $phase)->map(fn (PipelineStage $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'is_active' => $s->is_active,
                    'tasks_count' => $s->tasks_count,
                ])->values(),
            ], PipelinePhase::cases()),
        ]);
    }

    public function store(PipelineStageRequest $request, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('manage', PipelineStage::class);

        $data = $request->validated();

        $stage = DB::transaction(function () use ($data, $auditor) {
            $stage = PipelineStage::query()->create([
                'phase' => $data['phase'],
                'name' => $data['name'],
                'sort' => (int) PipelineStage::query()->where('phase', $data['phase'])->lockForUpdate()->max('sort') + 1,
                'is_active' => true,
            ]);
            $auditor->record('pipeline.stage_created', $stage, null, [
                'phase' => $stage->phase->value,
                'name' => $stage->name,
                'sort' => $stage->sort,
            ]);

            return $stage;
        });

        return back()->with('status', __('projects::messages.stage_created', ['name' => $stage->name]));
    }

    public function update(PipelineStageRequest $request, PipelineStage $stage, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('manage', PipelineStage::class);

        $data = $request->validated();
        $before = ['name' => $stage->name, 'is_active' => $stage->is_active];

        $stage->forceFill([
            'name' => $data['name'],
            'is_active' => (bool) ($data['is_active'] ?? $stage->is_active),
        ]);

        if (! $stage->isDirty()) {
            return back();
        }

        DB::transaction(function () use ($stage, $before, $auditor) {
            $stage->save();
            $auditor->record('pipeline.stage_updated', $stage, $before, ['name' => $stage->name, 'is_active' => $stage->is_active]);
        });

        $message = match (true) {
            $before['is_active'] && ! $stage->is_active => 'stage_deactivated',
            ! $before['is_active'] && $stage->is_active => 'stage_activated',
            default => 'stage_updated',
        };

        return back()->with('status', __('projects::messages.'.$message, ['name' => $stage->name]));
    }

    /** Swaps the stage with its neighbour in the same phase. Positions are renumbered 1..n on the way. */
    public function move(Request $request, PipelineStage $stage, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('manage', PipelineStage::class);

        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        DB::transaction(function () use ($stage, $direction, $auditor) {
            $ids = PipelineStage::query()->where('phase', $stage->phase)->lockForUpdate()->orderBy('sort')->orderBy('id')->pluck('id')->all();
            $at = array_search($stage->id, $ids, true);
            $to = $direction === 'up' ? $at - 1 : $at + 1;

            if ($at === false || ! isset($ids[$to])) {
                return;
            }

            $before = $ids;
            [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];

            foreach ($ids as $i => $id) {
                PipelineStage::query()->whereKey($id)->update(['sort' => $i + 1]);
            }

            $auditor->record('pipeline.stage_reordered', $stage, ['phase' => $stage->phase->value, 'order' => $before], ['phase' => $stage->phase->value, 'order' => $ids]);
        });

        return back();
    }

    /** Only a stage no task uses can go; a used one stays for the tasks' history and can be switched off instead. */
    public function destroy(PipelineStage $stage, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('manage', PipelineStage::class);

        $used = $stage->tasks()->withTrashed()->count();

        if ($used > 0) {
            throw ValidationException::withMessages([
                'stage' => trans_choice('projects::messages.stage_in_use', $used, ['count' => $used, 'name' => $stage->name]),
            ]);
        }

        DB::transaction(function () use ($stage, $auditor) {
            $auditor->record('pipeline.stage_deleted', $stage, ['phase' => $stage->phase->value, 'name' => $stage->name, 'sort' => $stage->sort], null);
            $stage->delete();
        });

        return back()->with('status', __('projects::messages.stage_deleted', ['name' => $stage->name]));
    }
}
