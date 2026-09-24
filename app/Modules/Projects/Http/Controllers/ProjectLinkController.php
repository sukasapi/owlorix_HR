<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Http\Requests\ProjectLinkRequest;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectLink;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Document links on the project page (docs/16). Read with the project page, set by people who manage projects. */
class ProjectLinkController extends Controller
{
    public function store(ProjectLinkRequest $request, Project $project, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('create', [ProjectLink::class, $project]);

        $data = $request->validated();

        $link = DB::transaction(function () use ($request, $project, $data, $auditor) {
            $link = $project->links()->create([
                'category' => $data['category'],
                'label' => $data['label'] ?? null,
                'url' => $data['url'],
                'note' => $data['note'] ?? null,
                'managers_only' => (bool) ($data['managers_only'] ?? false),
                'position' => (int) $project->links()->lockForUpdate()->max('position') + 1,
                'created_by' => $request->user()->id,
            ]);
            $auditor->record('project_link.created', $link, null, $this->snapshot($link));

            return $link;
        });

        return back()->with('status', __('projects::messages.link_created', ['name' => $link->title()]));
    }

    public function update(ProjectLinkRequest $request, Project $project, ProjectLink $link, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('update', $link);

        $data = $request->validated();

        DB::transaction(function () use ($link, $data, $auditor) {
            $before = $this->snapshot($link);
            $link->forceFill([
                'category' => $data['category'],
                'label' => $data['label'] ?? null,
                'url' => $data['url'],
                'note' => $data['note'] ?? null,
                'managers_only' => (bool) ($data['managers_only'] ?? $link->managers_only),
            ]);

            if ($link->isDirty()) {
                $link->save();
                $auditor->record('project_link.updated', $link, $before, $this->snapshot($link));
            }
        });

        return back()->with('status', __('projects::messages.link_updated', ['name' => $link->title()]));
    }

    public function destroy(Project $project, ProjectLink $link, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('delete', $link);

        DB::transaction(function () use ($link, $auditor) {
            $auditor->record('project_link.deleted', $link, $this->snapshot($link), null);
            $link->delete();
        });

        return back()->with('status', __('projects::messages.link_deleted', ['name' => $link->title()]));
    }

    /** Swaps the link with its neighbour. Positions are renumbered 1..n on the way, like pipeline stages. */
    public function move(Request $request, Project $project, ProjectLink $link, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('move', $link);

        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        DB::transaction(function () use ($project, $link, $direction, $auditor) {
            $ids = $project->links()->lockForUpdate()->orderBy('position')->orderBy('id')->pluck('id')->all();
            $at = array_search($link->id, $ids, true);
            $to = $direction === 'up' ? $at - 1 : $at + 1;

            if ($at === false || ! isset($ids[$to])) {
                return;
            }

            $before = $ids;
            [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];

            foreach ($ids as $i => $id) {
                ProjectLink::query()->whereKey($id)->update(['position' => $i + 1]);
            }

            $auditor->record('project_link.reordered', $link, ['project_id' => $project->id, 'order' => $before], ['project_id' => $project->id, 'order' => $ids]);
        });

        return back();
    }

    /** @return array<string, mixed> */
    private function snapshot(ProjectLink $link): array
    {
        return [
            'project_id' => $link->project_id,
            'category' => $link->category->value,
            'label' => $link->label,
            'url' => $link->url,
            'note' => $link->note,
            'managers_only' => $link->managers_only,
        ];
    }
}
