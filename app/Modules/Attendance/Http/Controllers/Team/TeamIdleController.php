<?php

namespace App\Modules\Attendance\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Models\IdlePeriod;
use App\Modules\Attendance\Models\IdleReview;
use App\Modules\Attendance\Services\TeamScope;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tim hari ini, tab PC diam: every quiet period of the people in the viewer's scope on one studio day, with the tag
 * and note the person gave on the desktop app and the lead's review (checked, asked, answered).
 */
class TeamIdleController extends Controller
{
    public function __invoke(Request $request, TeamScope $scope): Response
    {
        $viewer = $request->user();
        $today = Time::workDate(CarbonImmutable::now());
        $date = is_string($request->query('tanggal')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('tanggal')) === 1
            ? min($request->query('tanggal'), $today)
            : $today;
        $personId = filter_var($request->query('orang'), FILTER_VALIDATE_INT) ?: null;
        $unchecked = $request->boolean('belum');

        $people = $scope->people($viewer)->orderBy('name')->get(['id', 'name', 'username']);
        $person = $personId ? $people->firstWhere('id', $personId) : null;
        $ids = $person ? [$person->id] : $people->modelKeys();

        $periods = IdlePeriod::query()
            ->join('shifts', 'shifts.id', '=', 'idle_periods.shift_id')
            ->whereIn('shifts.user_id', $ids)
            ->where('shifts.work_date', $date)
            ->orderBy('idle_periods.started_at')
            ->get(['idle_periods.*', 'shifts.user_id']);

        $reviews = IdleReview::query()
            ->whereIn('shift_id', $periods->pluck('shift_id')->unique())
            ->with(['asker:id,name', 'checker:id,name'])
            ->get()
            ->keyBy(fn (IdleReview $r) => $r->shift_id.'|'.Time::db($r->started_at));

        $rows = $periods
            ->map(function (IdlePeriod $p) use ($reviews) {
                $review = $reviews->get($p->shift_id.'|'.Time::db($p->started_at));

                return [
                    'user_id' => (int) $p->getAttribute('user_id'),
                    'shift_id' => $p->shift_id,
                    'started_at' => Time::iso($p->started_at),
                    'ended_at' => Time::iso($p->ended_at),
                    'minutes' => $p->minutes,
                    'tag' => $p->tag?->value,
                    'note' => $p->note,
                    'review' => $review === null ? null : [
                        'status' => $review->status,
                        'question' => $review->question,
                        'answer' => $review->answer,
                        'asked_by' => $review->asker?->name,
                        'asked_at' => Time::iso($review->asked_at),
                        'answered_at' => Time::iso($review->answered_at),
                        'checked_by' => $review->checker?->name,
                        'checked_at' => Time::iso($review->checked_at),
                    ],
                ];
            })
            ->when($unchecked, fn ($rows) => $rows->filter(fn (array $row) => ($row['review']['status'] ?? null) !== IdleReview::CHECKED));

        $groups = $rows->groupBy('user_id')->map(fn ($items, $userId) => [
            'id' => (int) $userId,
            'name' => $people->firstWhere('id', (int) $userId)?->name ?? '',
            'total_minutes' => (int) $items->sum('minutes'),
            'untagged' => $items->whereNull('tag')->count(),
            'periods' => $items->values()->all(),
        ]);

        return Inertia::render('team-today/Idle', [
            'filters' => ['tanggal' => $date, 'orang' => $person?->id, 'belum' => $unchecked],
            'today' => $today,
            'people' => $people->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'username' => $u->username])->all(),
            'groups' => $people->pluck('id')->map(fn ($id) => $groups->get($id))->filter()->values()->all(),
        ]);
    }
}
