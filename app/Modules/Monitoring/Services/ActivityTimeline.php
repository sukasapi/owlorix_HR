<?php

namespace App\Modules\Monitoring\Services;

use App\Modules\Attendance\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Linimasa of Monitor aktivitas (docs/14 2.4): access_logs, audit_logs, and attendance_events as one list, newest
 * first. Each table is filtered and cut to the rows the requested page can reach before the UNION ALL, so a page reads
 * at most page x 50 rows per table instead of sorting every row.
 *
 * Order: time, then kind, then id, all descending except kind. Each branch is cut in the same order the outer query
 * sorts by, so rows sharing a timestamp never shift between pages. The total counts at most COUNT_CAP rows per table;
 * a total past MAX_PAGE x PER_PAGE is shown as "more than".
 */
class ActivityTimeline
{
    public const PER_PAGE = 50;

    /** Deeper pages would read too many rows per table; the date filters reach older entries */
    public const MAX_PAGE = 200;

    public const KINDS = ['access', 'change', 'attendance'];

    public const COUNT_CAP = self::MAX_PAGE * self::PER_PAGE + 1;

    /**
     * @param  array{person: ?int, kind: ?string, from: ?string, until: ?string}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function page(array $filters, int $page, string $path): LengthAwarePaginator
    {
        $page = max(1, min($page, self::MAX_PAGE));
        $branches = $this->branches($filters);
        $total = array_sum(array_map(fn (Builder $branch) => DB::query()->fromSub((clone $branch)->select(DB::raw('1 as one'))->limit(self::COUNT_CAP), 'capped')->count(), $branches));
        $reach = $page * self::PER_PAGE;

        $union = null;

        foreach ($branches as $column => $branch) {
            // Order and limit must be set before unionAll(), after it they apply to the whole union
            $branch->orderByDesc($column)->orderByDesc(Str::before($column, '.').'.id')->limit($reach);
            $union = $union === null ? $branch : $union->unionAll($branch);
        }

        $rows = DB::query()
            ->fromSub($union, 'timeline')
            ->orderByDesc('occurred_at')
            ->orderBy('kind')
            ->orderByDesc('sort_id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get();

        return new LengthAwarePaginator($rows, $total, self::PER_PAGE, $page, ['path' => $path]);
    }

    /**
     * One query per kind, keyed by the column it is ordered by.
     *
     * @param  array{person: ?int, kind: ?string, from: ?string, until: ?string}  $filters
     * @return array<string, Builder>
     */
    private function branches(array $filters): array
    {
        $from = $filters['from'] !== null ? Time::db(CarbonImmutable::parse($filters['from'], Time::zone())->startOfDay()) : null;
        $until = $filters['until'] !== null ? Time::db(CarbonImmutable::parse($filters['until'], Time::zone())->addDay()->startOfDay()) : null;

        $scoped = fn (Builder $query, string $person, string $time) => $query
            ->when($filters['person'] !== null, fn (Builder $q) => $q->where($person, $filters['person']))
            ->when($from !== null, fn (Builder $q) => $q->where($time, '>=', $from))
            ->when($until !== null, fn (Builder $q) => $q->where($time, '<', $until));

        $all = [
            'access' => fn () => ['access_logs.created_at' => $scoped(DB::table('access_logs')->selectRaw(
                "'access' as kind, cast(id as char) as row_id, lpad(id, 20, '0') as sort_id, user_id, event, created_at as occurred_at, ip, route_name, method, status, path, username, device_id"
            ), 'user_id', 'created_at')],
            'change' => fn () => ['audit_logs.created_at' => $scoped(DB::table('audit_logs')->selectRaw(
                "'change' as kind, cast(id as char) as row_id, lpad(id, 20, '0') as sort_id, actor_id as user_id, action as event, created_at as occurred_at, ip, null as route_name, null as method, null as status, null as path, null as username, null as device_id"
            ), 'actor_id', 'created_at')],
            'attendance' => fn () => ['attendance_events.occurred_at' => $scoped(DB::table('attendance_events')->selectRaw(
                "'attendance' as kind, id as row_id, id as sort_id, user_id, type as event, occurred_at, null as ip, null as route_name, null as method, null as status, null as path, null as username, device_id"
            ), 'user_id', 'occurred_at')],
        ];

        $branches = [];

        foreach ($all as $kind => $make) {
            if ($filters['kind'] === null || $filters['kind'] === $kind) {
                $branches += $make();
            }
        }

        return $branches;
    }
}
