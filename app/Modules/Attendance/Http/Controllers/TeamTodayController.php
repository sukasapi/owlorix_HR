<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Services\TeamBoard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tim hari ini (docs/01 4.2.6): who is clocked in, in overtime, quiet, or out right now. The page polls the `board`
 * prop every 30 seconds. Quiet PC detail is shown here because the page is Management only (3.6.4).
 */
class TeamTodayController extends Controller
{
    public function __invoke(Request $request, TeamBoard $board): Response
    {
        $data = $board->for($request->user());

        return Inertia::render('team-today/Index', [
            'scope' => $data['scope'],
            'board' => $data['board'],
        ]);
    }
}
