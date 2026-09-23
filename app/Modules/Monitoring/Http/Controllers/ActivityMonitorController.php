<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Calculation\ShiftRules;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Auth\AccountLookup;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Monitoring\Services\ActivityOverview;
use App\Modules\Monitoring\Services\ActivityTimeline;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Monitor aktivitas (docs/14 2): what needs checking, who is online, activity per day, and one timeline of access,
 * data changes, and attendance. Read-only, Superadmin only. Filter and page visits reload only the timeline.
 */
class ActivityMonitorController extends Controller
{
    /** Query values of `jenis`, as in the page's URL */
    private const KINDS = ['akses' => 'access', 'perubahan' => 'change', 'absensi' => 'attendance'];

    public function __invoke(Request $request, ActivityOverview $overview, ActivityTimeline $timeline, Settings $settings): Response
    {
        $now = CarbonImmutable::now();
        $filters = $this->filters($request);
        $viewer = $request->user();

        return Inertia::render('admin/activity/Index', [
            'attention' => fn () => $overview->attention($now),
            'online' => fn () => $overview->online($now),
            'chart' => fn () => $overview->perDay($now),
            'timeline' => fn () => $this->timeline($timeline, $filters, $request, $viewer),
            'filters' => [
                'person' => $filters['person'],
                'kind' => $filters['kind'] !== null ? array_search($filters['kind'], self::KINDS, true) : null,
                'from' => $filters['from'],
                'until' => $filters['until'],
            ],
            'options' => fn () => ['people' => User::withTrashed()->orderBy('name')->get(['id', 'name', 'username'])],
            'limits' => [
                'failed_threshold' => ActivityOverview::FAILED_THRESHOLD,
                'attention_hours' => ActivityOverview::ATTENTION_HOURS,
                'online_minutes' => ActivityOverview::ONLINE_MINUTES,
                'chart_days' => ActivityOverview::CHART_DAYS,
                'retention_days' => $settings->int('monitoring.access_log_days'),
                'max_page' => ActivityTimeline::MAX_PAGE,
            ],
            'links' => [
                'audit' => $viewer->hasPermission(Permission::ViewAuditLog),
                'settings' => $viewer->hasPermission(Permission::ManageSettings),
            ],
        ]);
    }

    /**
     * @param  array{person: ?int, kind: ?string, from: ?string, until: ?string}  $filters
     * @return array<string, mixed>
     */
    private function timeline(ActivityTimeline $timeline, array $filters, Request $request, User $viewer): array
    {
        $page = $timeline->page($filters, max(1, (int) $request->query('page', '1')), route('admin.activity.index', absolute: false));
        $page->withQueryString();
        $rows = $page->getCollection();

        $people = User::withTrashed()->whereIn('id', $rows->pluck('user_id')->filter()->unique())->get(['id', 'name', 'username'])->keyBy('id');
        $devices = Device::query()->whereIn('id', $rows->pluck('device_id')->filter()->unique())->pluck('hostname', 'id');
        $auditLinks = $viewer->hasPermission(Permission::ViewAuditLog);
        // Past what the pages reach, the count is capped per table (ActivityTimeline::COUNT_CAP) and shown as "more than"
        $reachable = ActivityTimeline::MAX_PAGE * ActivityTimeline::PER_PAGE;
        $capped = $page->total() > $reachable;

        return [
            'data' => $rows->map(fn (object $row) => $this->row($row, $people, $devices, $auditLinks))->values()->all(),
            'current_page' => $page->currentPage(),
            'last_page' => min($page->lastPage(), ActivityTimeline::MAX_PAGE),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
            'total' => $capped ? $reachable : $page->total(),
            'total_capped' => $capped,
            'prev_page_url' => $page->previousPageUrl(),
            'next_page_url' => $page->currentPage() < ActivityTimeline::MAX_PAGE ? $page->nextPageUrl() : null,
        ];
    }

    /**
     * @param  Collection<int, User>  $people
     * @param  Collection<string, string>  $devices
     * @return array<string, mixed>
     */
    private function row(object $row, Collection $people, Collection $devices, bool $auditLinks): array
    {
        $at = Time::parse($row->occurred_at);
        $person = $row->user_id !== null ? $people->get($row->user_id) : null;
        $deviceId = $row->device_id;

        return [
            'key' => $row->kind.'-'.$row->row_id,
            'kind' => $row->kind,
            'event' => $row->event,
            'at' => Time::iso($at),
            'person' => $person === null ? null : ['id' => $person->id, 'name' => $person->name, 'username' => $person->username],
            'username' => $row->username,
            'masked_prefix' => AccountLookup::maskedPrefix($row->username),
            'route' => $row->route_name,
            'method' => $row->method,
            'status' => $row->status !== null ? (int) $row->status : null,
            'path' => $row->path,
            'ip' => $row->ip,
            'device' => $deviceId === null ? null : [
                'id' => $deviceId,
                'kind' => ShiftRules::isWebDevice($deviceId) ? 'browser' : 'desktop',
                'hostname' => $devices[$deviceId] ?? null,
            ],
            'audit_href' => $row->kind === 'change' && $auditLinks
                ? route('admin.audit.index', array_filter([
                    'pelaku' => $row->user_id,
                    'dari' => Time::workDate($at),
                    'sampai' => Time::workDate($at),
                ]), false)
                : null,
        ];
    }

    /** @return array{person: ?int, kind: ?string, from: ?string, until: ?string} */
    private function filters(Request $request): array
    {
        $date = fn (mixed $value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
        $person = $request->query('orang');
        $kind = $request->query('jenis');

        $from = $date($request->query('dari'));
        $until = $date($request->query('sampai'));

        if ($from !== null && $until !== null && $from > $until) {
            [$from, $until] = [$until, $from];
        }

        return [
            'person' => is_string($person) && ctype_digit($person) && strlen($person) < 19 ? (int) $person : null,
            'kind' => is_string($kind) ? (self::KINDS[$kind] ?? null) : null,
            'from' => $from,
            'until' => $until,
        ];
    }
}
