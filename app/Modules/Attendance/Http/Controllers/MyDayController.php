<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Services\TodaySummary;
use App\Modules\Calendar\Services\WorkdayResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hari ini: today's shifts, regular and overtime minutes, PC quiet periods, and today's calendar status.
 * The page polls this route while a shift is running.
 */
class MyDayController extends Controller
{
    public function __invoke(Request $request, TodaySummary $summary, WorkdayResolver $calendar): Response
    {
        $user = $request->user();
        $today = $summary->for($user);

        return Inertia::render('my-day/Index', [
            'summary' => $today,
            'day' => $calendar->verdict($user, $today['date'])->toArray(),
        ]);
    }
}
