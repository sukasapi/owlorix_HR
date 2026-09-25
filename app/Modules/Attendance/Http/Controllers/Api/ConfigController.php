<?php

namespace App\Modules\Attendance\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Support\Time;
use App\Modules\Calendar\Services\DayVerdict;
use App\Modules\Calendar\Services\WorkdayResolver;
use App\Modules\Monitoring\Services\AppUsagePolicy;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rule values and the person's calendar, cached by the app so prompts and the non-workday notice work offline (3.8.6). */
class ConfigController extends Controller
{
    private const CALENDAR_MONTHS = 3;

    public function __invoke(Request $request, Settings $settings, WorkdayResolver $calendar, AppUsagePolicy $appUsage): JsonResponse
    {
        $now = CarbonImmutable::now();
        $today = Time::workDate($now);
        $until = CarbonImmutable::parse($today)->addMonthsNoOverflow(self::CALENDAR_MONTHS)->toDateString();

        return response()->json([
            'server_time' => Time::iso($now),
            'timezone' => Time::zone(),
            // Aktivitas detail is sent as this person's answer (rule on and their employment type recorded), so the
            // desktop app needs no knowledge of employment types
            'settings' => ['monitoring.app_usage' => $appUsage->records($request->user())] + $settings->all(),
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
