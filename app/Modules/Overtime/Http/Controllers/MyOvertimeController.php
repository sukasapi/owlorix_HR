<?php

namespace App\Modules\Overtime\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Services\MyOvertime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lembur: the signed-in person's own overtime requests (`?status=pending|approved|rejected&bulan=YYYY-MM&page=N`).
 * Unknown filter values fall back to showing everything.
 */
class MyOvertimeController extends Controller
{
    public function __invoke(Request $request, MyOvertime $overtime): Response
    {
        $status = $request->query('status');
        $month = $request->query('bulan');

        $filters = [
            'status' => is_string($status) && OvertimeStatus::tryFrom($status) !== null ? $status : 'all',
            'bulan' => is_string($month) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) && (int) $m[1] >= 2000 && (int) $m[1] <= 2999 ? $month : null,
        ];

        return Inertia::render('overtime/Index', $overtime->build($request->user(), $filters, CarbonImmutable::now()));
    }
}
