<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Actions\DecideLeave;
use App\Modules\Leave\Enums\LeaveDecision;
use App\Modules\Leave\Http\Requests\LeaveDecisionRequest;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveBalance;
use App\Modules\Leave\Services\LeavePresenter;
use App\Modules\Leave\Services\PendingLeave;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Persetujuan cuti: pending requests the viewer may decide, oldest first (the focal point), each with the
 * person's annual leave left and teammates already off on those dates, plus the latest closed requests.
 */
class LeaveApprovalsController extends Controller
{
    public const RECENT_LIMIT = 20;

    public function index(Request $request, PendingLeave $pending, LeavePresenter $presenter, LeaveBalance $balance): Response
    {
        $viewer = $request->user();
        $now = CarbonImmutable::now();

        $open = $presenter->withDetails($pending->decidableBy($viewer))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $recent = $presenter->withDetails($pending->closedVisibleTo($viewer))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get();

        // The quota is a record for Superadmin only (owner, 2026-09-23): approvers without leave.manage get no numbers
        $withBalance = $viewer->hasPermission(Permission::ManageLeave);
        $balances = $withBalance
            ? $open->groupBy(fn (LeaveRequest $r) => $r->year())
                ->map(fn (Collection $group, int $year) => $balance->forMany($group->pluck('user_id')->all(), $year))
            : collect();
        $alsoOff = $this->teammatesOff($open);

        return Inertia::render('leave/Approvals', [
            'pending' => $open->map(fn (LeaveRequest $r) => [
                // PendingLeave mirrors LeaveApprovers::canDecide, so every request it returns is one the viewer decides
                ...$presenter->item($r, $viewer, $now, canDecide: $r->user !== null),
                ...($withBalance ? ['balance' => $r->type?->counts_against_quota ? ($balances[$r->year()][$r->user_id] ?? null) : null] : []),
                'also_off' => $alsoOff[$r->id] ?? [],
            ])->values()->all(),
            'recent' => $recent->map(fn (LeaveRequest $r) => $presenter->item($r, $viewer, $now))->values()->all(),
            'recent_limit' => self::RECENT_LIMIT,
        ]);
    }

    public function decide(LeaveDecisionRequest $form, LeaveRequest $leave, DecideLeave $decide): RedirectResponse
    {
        $decision = LeaveDecision::from($form->validated('decision'));

        try {
            $decide($form->user(), $leave, $decision, $form->validated('note'));
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['leave' => $e->getMessage()]);
        }

        $name = $leave->user?->name ?? '';

        return back()->with('status', $decision === LeaveDecision::Approved
            ? __('leave::messages.approved', ['name' => $name])
            : __('leave::messages.rejected', ['name' => $name]));
    }

    /**
     * Per pending request: people sharing a team with the requester who have pending or approved leave on
     * overlapping dates, so a lead sees who else would be away. One query covers every pending request.
     *
     * @param  Collection<int, LeaveRequest>  $pending  with user.teams loaded
     * @return array<int, list<array{name: string, start_date: string, end_date: string, status: string}>> keyed by request id
     */
    private function teammatesOff(Collection $pending): array
    {
        $teamsOf = $pending->mapWithKeys(fn (LeaveRequest $r) => [$r->id => $r->user?->teams->modelKeys() ?? []]);
        $teamIds = $teamsOf->flatten()->unique()->values()->all();

        if ($teamIds === []) {
            return [];
        }

        $holding = LeaveRequest::query()
            ->holding()
            ->overlapping($pending->min(fn (LeaveRequest $r) => $r->startDate()), $pending->max(fn (LeaveRequest $r) => $r->endDate()))
            ->whereIn('user_id', DB::table('team_user')->whereIn('team_id', $teamIds)->select('user_id'))
            ->select('leave_requests.*')
            ->selectSub(DB::table('team_user')->whereColumn('team_user.user_id', 'leave_requests.user_id')->whereIn('team_id', $teamIds)->selectRaw('group_concat(team_id)'), 'shared_team_ids')
            ->with('user:id,name')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $result = [];

        foreach ($pending as $request) {
            $teams = array_flip($teamsOf[$request->id]);

            if ($teams === []) {
                continue;
            }

            $result[$request->id] = $holding
                ->filter(fn (LeaveRequest $r) => (int) $r->id !== (int) $request->id
                    && (int) $r->user_id !== (int) $request->user_id
                    && $r->startDate() <= $request->endDate()
                    && $r->endDate() >= $request->startDate()
                    && array_intersect_key(array_flip(explode(',', (string) $r->shared_team_ids)), $teams) !== [])
                ->take(10)
                ->map(fn (LeaveRequest $r) => [
                    'name' => $r->user instanceof User ? $r->user->name : '',
                    'start_date' => $r->startDate(),
                    'end_date' => $r->endDate(),
                    'status' => $r->status->value,
                ])
                ->values()
                ->all();
        }

        return $result;
    }
}
