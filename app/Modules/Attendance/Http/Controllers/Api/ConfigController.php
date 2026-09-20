<?php

namespace App\Modules\Attendance\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Support\Time;
use App\Modules\Calendar\Services\DayVerdict;
use App\Modules\Calendar\Services\WorkdayResolver;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rule values and the person's calendar, cached by the app so prompts and the non-workday notice work offline (3.8.6). */
class ConfigController extends Controller
{
    private const CALENDAR_MONTHS = 3;

    public function __invoke(Request $request, Settings $settings, WorkdayResolver $calendar): JsonResponse
    {
        $now = CarbonImmutable::now();
        $today = Time::workDate($now);
        $until = CarbonImmutable::parse($today)->addMonthsNoOverflow(self::CALENDAR_MONTHS)->toDateString();

        return response()->json([
            'server_time' => Time::iso($now),
            'timezone' => Time::zone(),
            'settings' => $settings->all(),
            'calendar' => array_values(array_map(
                fn (DayVerdict $day) => $day->toArray(),
                $calendar->range($request->user(), $today, $until),
            )),
            'app' => [
                'latest_version' => null,
                'min_version' => null,
                'download_url' => null,
            ],
        ]);
    }
}
