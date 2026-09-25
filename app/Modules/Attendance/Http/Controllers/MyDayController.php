<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Models\IdlePeriod;
use App\Modules\Attendance\Models\IdleReview;
use App\Modules\Attendance\Services\TodaySummary;
use App\Modules\Attendance\Services\WeekTarget;
use App\Modules\Attendance\Support\Time;
use App\Modules\Calendar\Services\WorkdayResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hari ini: today's shifts, regular and overtime minutes, PC quiet periods, today's calendar status, and this week
 * against the person's weekly target (docs/02 3.12; null for a type without one).
 * The page polls this route while a shift is running.
 */
class MyDayController extends Controller
{
    public function __invoke(Request $request, TodaySummary $summary, WorkdayResolver $calendar, WeekTarget $week): Response
    {
        $user = $request->user();
        $today = $summary->for($user);

        return Inertia::render('my-day/Index', [
            'summary' => $today,
            'day' => $calendar->verdict($user, $today['date'])->toArray(),
            'week' => $week->forUser($user, WeekTarget::mondayOf($today['date'])),
            'idle_questions' => $this->idleQuestions($user->id),
        ]);
    }

    /**
     * Questions a lead asked about this person's PC diam periods and that still wait for an answer (Perlu kamu).
     *
     * @return list<array<string, mixed>>
     */
    private function idleQuestions(int $userId): array
    {
        return IdleReview::query()
            ->where('user_id', $userId)
            ->where('status', IdleReview::ASKED)
            ->with(['asker:id,name', 'shift:id,work_date'])
            ->orderBy('asked_at')
            ->get()
            ->map(function (IdleReview $review) {
                $period = IdlePeriod::query()->where('shift_id', $review->shift_id)->where('started_at', Time::db($review->started_at))->first();

                return [
                    'id' => $review->id,
                    'work_date' => $review->shift?->work_date,
                    'started_at' => Time::iso($review->started_at),
                    'ended_at' => Time::iso($period?->ended_at),
                    'minutes' => $period?->minutes,
                    'tag' => $period?->tag?->value,
                    'question' => $review->question,
                    'asked_by' => $review->asker?->name,
                ];
            })
            ->all();
    }
}
