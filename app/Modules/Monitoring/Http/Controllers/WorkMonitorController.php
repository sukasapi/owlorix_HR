<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Monitoring\Services\WorkMonitor;
use App\Modules\Projects\Services\HourBudget;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Monitor kerja (docs/14 5.1): what is stuck or late, whether work gets finished as fast as it comes in, where the
 * hours went, and (for budget holders) whether the hour budgets hold. Query: `proyek` (id) and `minggu` (4, 8, 12).
 */
class WorkMonitorController extends Controller
{
    public function __invoke(Request $request, WorkMonitor $monitor): Response
    {
        $viewer = $request->user();
        $project = filter_var($request->query('proyek'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $weeks = (int) $request->query('minggu', (string) WorkMonitor::WEEK_OPTIONS[0]);

        return Inertia::render('monitoring/Work', $monitor->build(
            $viewer,
            $project === false ? null : $project,
            in_array($weeks, WorkMonitor::WEEK_OPTIONS, true) ? $weeks : WorkMonitor::WEEK_OPTIONS[0],
            HourBudget::canSee($viewer),
        ));
    }
}
