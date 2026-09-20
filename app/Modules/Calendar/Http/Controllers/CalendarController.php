<?php

namespace App\Modules\Calendar\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Calendar\Services\CalendarMonth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kalender: month view for Superadmin (studio calendar) and Management (opened workdays).
 */
class CalendarController extends Controller
{
    public function __invoke(Request $request, CalendarMonth $calendar): Response
    {
        $today = CarbonImmutable::now(config('owlorix.display_timezone'));
        $month = $calendar->resolveMonth($request->query('bulan'), $today);

        return Inertia::render('calendar/Index', $calendar->build($request->user(), $month, $today));
    }
}
