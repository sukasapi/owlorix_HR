<?php

namespace App\Modules\Attendance\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Models\IdlePeriod;
use App\Modules\Attendance\Models\IdleReview;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\TeamScope;
use App\Modules\Attendance\Support\Time;
use App\Modules\Shared\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A lead's review of one PC diam period: "Sudah dicek", or a question the person answers on Hari ini. Only people in
 * the lead's scope; each step is written to the audit log.
 */
class IdleReviewController extends Controller
{
    public function check(Request $request, TeamScope $scope, Auditor $auditor): RedirectResponse
    {
        [$shift, $period] = $this->period($request, $scope);
        $review = $this->review($shift, $period);
        $before = $review->exists ? ['status' => $review->status] : null;

        $review->fill([
            'status' => IdleReview::CHECKED,
            'checked_by' => $request->user()->id,
            'checked_at' => CarbonImmutable::now(),
        ])->save();

        $auditor->record('idle_review.checked', $review, $before, ['status' => IdleReview::CHECKED, 'user_id' => $shift->user_id, 'started_at' => Time::iso($period->started_at)]);

        return back();
    }

    public function ask(Request $request, TeamScope $scope, Auditor $auditor): RedirectResponse
    {
        $data = $request->validate(['question' => ['nullable', 'string', 'max:500']]);
        [$shift, $period] = $this->period($request, $scope);
        $review = $this->review($shift, $period);
        $before = $review->exists ? ['status' => $review->status] : null;

        $review->fill([
            'status' => IdleReview::ASKED,
            'question' => filled($data['question'] ?? null) ? trim($data['question']) : null,
            'answer' => null,
            'answered_at' => null,
            'asked_by' => $request->user()->id,
            'asked_at' => CarbonImmutable::now(),
            'checked_by' => null,
            'checked_at' => null,
        ])->save();

        $auditor->record('idle_review.asked', $review, $before, ['status' => IdleReview::ASKED, 'user_id' => $shift->user_id, 'started_at' => Time::iso($period->started_at)]);

        return back();
    }

    /** @return array{0: Shift, 1: IdlePeriod} */
    private function period(Request $request, TeamScope $scope): array
    {
        $data = $request->validate([
            'shift_id' => ['required', 'integer'],
            'started_at' => ['required', 'date'],
        ]);

        $shift = Shift::query()->findOrFail($data['shift_id']);
        abort_unless($scope->includes($request->user(), $shift->user_id), 403);

        $period = IdlePeriod::query()
            ->where('shift_id', $shift->id)
            ->where('started_at', Time::db(CarbonImmutable::parse($data['started_at'])))
            ->firstOrFail();

        return [$shift, $period];
    }

    private function review(Shift $shift, IdlePeriod $period): IdleReview
    {
        return IdleReview::query()->firstOrNew(
            ['shift_id' => $shift->id, 'started_at' => Time::db($period->started_at)],
            ['user_id' => $shift->user_id],
        );
    }
}
