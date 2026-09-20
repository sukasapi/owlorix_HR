<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Services\HistoryMonth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Riwayat: the signed-in person's own shifts for one month (`?bulan=YYYY-MM`), grouped by work date, with totals.
 */
class HistoryController extends Controller
{
    public function __invoke(Request $request, HistoryMonth $history): Response
    {
        $now = CarbonImmutable::now();
        $month = $history->resolveMonth($request->query('bulan'), $now);

        return Inertia::render('history/Index', $history->build($request->user(), $month, $now));
    }
}
