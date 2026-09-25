<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Monitoring\Services\AppUsagePolicy;
use App\Modules\Shared\Audit\Auditor;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aktivitas detail for one person and one studio day: which applications were in front, for how long, with the
 * window title. Reads the active table and the archive, so an old date still opens. Every look at a person's day is
 * written to the audit log, because this data is more private than attendance.
 */
class AppUsageController extends Controller
{
    private const TOP_APPS = 12;

    public function __invoke(Request $request, Settings $settings, Auditor $auditor, AppUsagePolicy $policy): Response
    {
        $today = Time::workDate(CarbonImmutable::now());
        $date = is_string($request->query('tanggal')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('tanggal')) === 1
            ? $request->query('tanggal')
            : $today;
        $date = min($date, $today);
        $personId = filter_var($request->query('orang'), FILTER_VALIDATE_INT) ?: null;

        $people = User::query()->orderBy('name')->get(['id', 'name', 'username', 'status', 'employment_type'])
            ->filter(fn (User $user) => $user->hasPermission(Permission::ClockIn))
            ->values();
        $person = $personId ? $people->firstWhere('id', $personId) : null;

        $props = [
            'enabled' => (bool) $settings->get('monitoring.app_usage'),
            'active_days' => $settings->int('monitoring.app_usage_active_days'),
            'filters' => ['orang' => $person?->id, 'tanggal' => $date],
            'today' => $today,
            'people' => $people->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'username' => $user->username])->all(),
            'person' => null,
        ];

        if ($person !== null) {
            $auditor->record('app_usage.viewed', $person, null, ['date' => $date]);
            $props['person'] = [...$this->day($person, $date), 'recorded' => $policy->records($person), 'employment_type' => $person->employment_type?->value];
        }

        return Inertia::render('monitoring/AppUsage', $props);
    }

    /** @return array<string, mixed> */
    private function day(User $person, string $date): array
    {
        $from = CarbonImmutable::parse($date, Time::zone())->startOfDay();
        $to = $from->addDay();
        $range = [Time::db($from), Time::db($to)];

        $columns = ['app_name', 'window_title', 'is_browser', 'started_at', 'ended_at', 'seconds'];
        $active = DB::table('app_usage_sessions')->where('user_id', $person->id)->whereBetween('started_at', $range)->select([...$columns, DB::raw('0 as archived')]);
        $rows = DB::table('app_usage_archive')->where('user_id', $person->id)->whereBetween('started_at', $range)->select([...$columns, DB::raw('1 as archived')])
            ->unionAll($active)
            ->orderBy('started_at')
            ->get();

        $sessions = $rows->map(fn ($row) => [
            'app' => $row->app_name,
            'title' => $row->window_title,
            'browser' => (bool) $row->is_browser,
            'started_at' => Time::iso(CarbonImmutable::parse($row->started_at, 'UTC')),
            'ended_at' => Time::iso(CarbonImmutable::parse($row->ended_at, 'UTC')),
            'seconds' => (int) $row->seconds,
        ]);

        $shifts = Shift::query()->where('user_id', $person->id)
            ->where('clock_in_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('clock_out_at')->orWhere('clock_out_at', '>=', $from))
            ->orderBy('clock_in_at')
            ->get(['clock_in_at', 'clock_out_at'])
            ->map(fn (Shift $shift) => ['clock_in_at' => Time::iso($shift->clock_in_at), 'clock_out_at' => Time::iso($shift->clock_out_at)])
            ->all();

        return [
            'id' => $person->id,
            'name' => $person->name,
            'from_archive' => $rows->contains(fn ($row) => (int) $row->archived === 1),
            'total_seconds' => (int) $rows->sum('seconds'),
            'browser_seconds' => (int) $rows->where('is_browser', true)->sum('seconds'),
            'apps' => $this->apps($rows),
            'sessions' => $sessions->all(),
            'shifts' => $shifts,
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array{app: string, browser: bool, seconds: int}>
     */
    private function apps(Collection $rows): array
    {
        return $rows->groupBy('app_name')
            ->map(fn (Collection $group, string $app) => ['app' => $app, 'browser' => (bool) $group->first()->is_browser, 'seconds' => (int) $group->sum('seconds')])
            ->sortByDesc('seconds')
            ->take(self::TOP_APPS)
            ->values()
            ->all();
    }
}
