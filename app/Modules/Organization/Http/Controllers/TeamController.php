<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Events\TeamMembersChanged;
use App\Modules\Organization\Http\Requests\StoreTeamRequest;
use App\Modules\Organization\Http\Requests\TeamMessages;
use App\Modules\Organization\Http\Requests\UpdateTeamRequest;
use App\Modules\Organization\Models\Team;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tim: Superadmin creates teams, sets the Team Lead, and manages members.
 */
class TeamController extends Controller
{
    public function index(): Response
    {
        $teams = Team::query()
            ->with(['members' => fn ($q) => $q->with('roles:id,name')->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'lead_user_id' => $team->lead_user_id === null ? null : (int) $team->lead_user_id,
                'members' => $team->members->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'initials' => $user->initials(),
                    'status' => $user->status->value,
                    'can_lead' => $user->roles->contains('name', Role::TeamLead->value),
                ])->values(),
            ]);

        return Inertia::render('admin/teams/Index', [
            'teams' => $teams,
            'people' => User::query()->orderBy('name')->get(['id', 'name', 'username', 'status'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'status' => $user->status->value,
                ]),
        ]);
    }

    public function store(StoreTeamRequest $request, Auditor $auditor): RedirectResponse
    {
        $team = DB::transaction(function () use ($request, $auditor) {
            $team = Team::query()->create(['name' => $request->validated('name')]);
            $auditor->record('team.created', $team, null, ['name' => $team->name]);

            return $team;
        });

        return back()->with('status', TeamMessages::get('created', ['name' => $team->name]));
    }

    public function update(UpdateTeamRequest $request, Team $team, Auditor $auditor): RedirectResponse
    {
        DB::transaction(function () use ($request, $team, $auditor) {
            $name = $request->validated('name');
            $lead = $request->validated('lead_user_id');
            $lead = $lead === null ? null : (int) $lead;

            $beforeName = $team->name;
            $beforeLead = $team->lead_user_id === null ? null : (int) $team->lead_user_id;

            $team->forceFill(['name' => $name, 'lead_user_id' => $lead])->save();

            if ($beforeName !== $name) {
                $auditor->record('team.renamed', $team, ['name' => $beforeName], ['name' => $name]);
            }
            if ($beforeLead !== $lead) {
                $auditor->record('team.lead_changed', $team, ['lead_user_id' => $beforeLead], ['lead_user_id' => $lead]);
            }
        });

        return back()->with('status', TeamMessages::get('saved', ['name' => $team->name]));
    }

    public function destroy(Team $team, Auditor $auditor): RedirectResponse
    {
        $name = $team->name;

        DB::transaction(function () use ($team, $auditor) {
            $before = [
                'name' => $team->name,
                'lead_user_id' => $team->lead_user_id === null ? null : (int) $team->lead_user_id,
                'member_ids' => $team->members()->pluck('users.id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            ];

            $team->members()->detach();
            $auditor->record('team.deleted', $team, $before, null);
            TeamMembersChanged::dispatch($team->id, $before['member_ids'], teamDeleted: true);
            $team->delete();
        });

        return back()->with('status', TeamMessages::get('deleted', ['name' => $name]));
    }
}
