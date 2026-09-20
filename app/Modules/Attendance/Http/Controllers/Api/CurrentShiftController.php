<?php

namespace App\Modules\Attendance\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Services\DesktopState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentShiftController extends Controller
{
    public function __invoke(Request $request, DesktopState $state): JsonResponse
    {
        return response()->json($state->for($request->user()));
    }
}
