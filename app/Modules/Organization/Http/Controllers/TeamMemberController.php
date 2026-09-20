<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Actions\TeamMembership;
use App\Modules\Organization\Http\Requests\AddTeamMemberRequest;
use App\Modules\Organization\Http\Requests\TeamMessages;
use App\Modules\Organization\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class TeamMemberController extends Controller
{
    public function store(AddTeamMemberRequest $request, Team $team, TeamMembership $membership): RedirectResponse
    {
        $user = User::query()->findOrFail($request->validated('user_id'));

        DB::transaction(fn () => $membership->add($team, $user));

        return back()->with('status', TeamMessages::get('member_added', ['person' => $user->name, 'name' => $team->name]));
    }

    public function destroy(Team $team, User $user, TeamMembership $membership): RedirectResponse
    {
        abort_unless($team->members()->whereKey($user->id)->exists(), 404);

        DB::transaction(fn () => $membership->remove($team, $user));

        return back()->with('status', TeamMessages::get('member_removed', ['person' => $user->name, 'name' => $team->name]));
    }
}
