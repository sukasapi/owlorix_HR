<?php

namespace App\Modules\Monitoring\Services;

use App\Modules\Attendance\Calculation\ShiftRules;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Auth\AccountLookup;
use App\Modules\Identity\Models\User;
use App\Modules\Monitoring\Enums\AccessEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The top of Monitor aktivitas (docs/14 2.4): what needs checking, who is online now, and activity per studio day.
 */
class ActivityOverview
{
    /** Failed sign-ins on one username within the window before it is listed */
    public const FAILED_THRESHOLD = 5;

    public const ATTENTION_HOURS = 24;

    public const ONLINE_MINUTES = 5;

    public const CHART_DAYS = 30;

    /**
     * @return array{failed: list<array<string, mixed>>, forbidden: list<array<string, mixed>>}
     */
    public function attention(CarbonImmutable $now): array
    {
        $since = Time::db($now->subHours(self::ATTENTION_HOURS));

        $failed = DB::table('access_logs')
            ->whereIn('event', AccessEvent::failedSignIns())
            ->where('created_at', '>=', $since)
            ->whereNotNull('username')
            ->groupBy('username')
            ->havingRaw('count(*) >= ?', [self::FAILED_THRESHOLD])
            ->selectRaw('username, count(*) as attempts, sum(event = ?) as locked, max(created_at) as last_at', [AccessEvent::LockedOut->value])
            ->orderByDesc('last_at')
            ->get();

        $lastFailed = $this->latestPer('username', $failed->pluck('username')->all(), $since, fn ($q) => $q->whereIn('event', AccessEvent::failedSignIns()));
        $owners = User::withTrashed()->whereIn('username', $failed->pluck('username'))->get(['id', 'name', 'username'])->keyBy('username');

        $forbidden = DB::table('access_logs')
            ->where('event', AccessEvent::Forbidden->value)
            ->where('status', 403)
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, count(*) as refusals, max(created_at) as last_at')
            ->orderByDesc('last_at')
            ->get();

        $lastForbidden = $this->latestPer('user_id', $forbidden->pluck('user_id')->all(), $since, fn ($q) => $q->where('event', AccessEvent::Forbidden->value)->where('status', 403));
        $people = User::withTrashed()->whereIn('id', $forbidden->pluck('user_id'))->get(['id', 'name', 'username'])->keyBy('id');

        return [
            'failed' => $failed->map(fn (object $row) => [
                'username' => $row->username,
                // Set when the tries used text that matches no account; only its start is kept (AccountLookup::logName)
                'masked_prefix' => AccountLookup::maskedPrefix($row->username),
                'attempts' => (int) $row->attempts,
                'locked' => (int) $row->locked > 0,
                'last_at' => $this->iso($row->last_at),
                'last_ip' => $lastFailed[$row->username]->ip ?? null,
                'last_device' => $lastFailed[$row->username]->device_id ?? null,
                'person' => $this->person($owners->get($row->username)),
            ])->values()->all(),
            'forbidden' => $forbidden->map(fn (object $row) => [
                'person' => $this->person($people->get($row->user_id)),
                'refusals' => (int) $row->refusals,
                'last_at' => $this->iso($row->last_at),
                'last_route' => $lastForbidden[$row->user_id]->route_name ?? null,
                'last_path' => $lastForbidden[$row->user_id]->path ?? null,
                'last_ip' => $lastForbidden[$row->user_id]->ip ?? null,
            ])->filter(fn (array $row) => $row['person'] !== null)->values()->all(),
        ];
    }

    /**
     * Web sessions active in the last minutes (only with the database session driver) and desktop apps whose token
     * was used in the same window (every sync and heartbeat upload uses it).
     *
     * @return array{web: list<array<string, mixed>>, desktop: list<array<string, mixed>>, web_tracked: bool}
     */
    public function online(CarbonImmutable $now): array
    {
        $tracked = config('session.driver') === 'database';
        $since = $now->subMinutes(self::ONLINE_MINUTES);

        $web = $tracked
            ? DB::table(config('session.table', 'sessions').' as s')
                ->join('users as u', 'u.id', '=', 's.user_id')
                ->whereNull('u.deleted_at')
                ->where('s.last_activity', '>=', $since->getTimestamp())
                ->orderByDesc('s.last_activity')
                ->get(['u.id', 'u.name', 'u.username', 's.user_agent', 's.ip_address', 's.last_activity'])
                ->map(fn (object $row) => [
                    'person' => ['id' => $row->id, 'name' => $row->name, 'username' => $row->username],
                    ...BrowserName::from($row->user_agent),
                    'ip' => $row->ip_address,
                    'last_seen_at' => Time::iso(CarbonImmutable::createFromTimestampUTC((int) $row->last_activity)),
                ])->values()->all()
            : [];

        $desktop = DB::table('personal_access_tokens as t')
            ->join('devices as d', 'd.id', '=', 't.name')
            ->join('users as u', 'u.id', '=', 't.tokenable_id')
            ->where('t.tokenable_type', (new User)->getMorphClass())
            ->where('t.last_used_at', '>=', $since->format('Y-m-d H:i:s'))
            ->where('d.id', 'not like', ShiftRules::WEB_DEVICE_PREFIX.'%')
            ->whereNull('d.revoked_at')
            ->whereNull('u.deleted_at')
            ->orderByDesc('t.last_used_at')
            ->get(['u.id', 'u.name', 'u.username', 'd.id as device_id', 'd.hostname', 'd.app_version', 't.last_used_at'])
            ->map(fn (object $row) => [
                'person' => ['id' => $row->id, 'name' => $row->name, 'username' => $row->username],
                'device_id' => $row->device_id,
                'hostname' => $row->hostname,
                'app_version' => $row->app_version,
                'last_seen_at' => $this->iso($row->last_used_at),
            ])->values()->all();

        return ['web' => $web, 'desktop' => $desktop, 'web_tracked' => $tracked];
    }

    /**
     * Rows per studio day (Asia/Jakarta) for the last CHART_DAYS days, today included.
     *
     * @return list<array{date: string, access: int, change: int, attendance: int}>
     */
    public function perDay(CarbonImmutable $now): array
    {
        $today = $now->setTimezone(Time::zone())->startOfDay();
        $start = $today->subDays(self::CHART_DAYS - 1);
        $since = Time::db($start);
        // Jakarta has no daylight saving, so one offset shifts every stored UTC time to its studio date
        $offset = $today->utcOffset();

        $count = fn (string $table, string $column) => DB::table($table)
            ->where($column, '>=', $since)
            ->selectRaw("date(date_add({$column}, interval ? minute)) as day, count(*) as total", [$offset])
            ->groupBy('day')
            ->pluck('total', 'day');

        $access = $count('access_logs', 'created_at');
        $change = $count('audit_logs', 'created_at');
        $attendance = $count('attendance_events', 'occurred_at');

        $days = [];

        for ($day = $start; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            $date = $day->toDateString();
            $days[] = [
                'date' => $date,
                'access' => (int) ($access[$date] ?? 0),
                'change' => (int) ($change[$date] ?? 0),
                'attendance' => (int) ($attendance[$date] ?? 0),
            ];
        }

        return $days;
    }

    /**
     * The newest matching access row per key, for the few keys already listed.
     *
     * @param  list<int|string>  $keys
     * @return array<int|string, object>
     */
    private function latestPer(string $column, array $keys, string $since, callable $scope): array
    {
        if ($keys === []) {
            return [];
        }

        $latest = [];

        $rows = DB::table('access_logs')
            ->whereIn('id', fn ($q) => $q->from('access_logs')
                ->selectRaw('max(id)')
                ->whereIn($column, $keys)
                ->where('created_at', '>=', $since)
                ->tap($scope)
                ->groupBy($column))
            ->get([$column, 'ip', 'device_id', 'route_name', 'path']);

        foreach ($rows as $row) {
            $latest[$row->{$column}] = $row;
        }

        return $latest;
    }

    /** @return array{id: int, name: string, username: string}|null */
    private function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name, 'username' => $user->username];
    }

    private function iso(?string $utc): ?string
    {
        return $utc === null ? null : Time::iso(Time::parse($utc));
    }
}
