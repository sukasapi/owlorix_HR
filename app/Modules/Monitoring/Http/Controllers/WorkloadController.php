<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Monitoring\Services\Workload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Beban kerja (docs/14 5.2): capacity against planned work per person for one week. Query: `minggu` (a date in the week). */
class WorkloadController extends Controller
{
    public function __invoke(Request $request, Workload $workload): Response
    {
        $week = $request->query('minggu');

        return Inertia::render('monitoring/Workload', $workload->build(
            $request->user(),
            Workload::weekStart(is_string($week) ? $week : null),
        ));
    }
}
