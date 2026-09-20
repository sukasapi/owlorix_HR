<?php

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Calculation\ShiftRules;
use App\Modules\Attendance\Services\WebDevice;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Audit\AuditLog;
use App\Modules\Shared\Audit\Auditor;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Perangkat: studio PCs and browsers that recorded attendance (01 section 6, 03 section 5). Superadmin revokes a device
 * with a reason: its tokens are deleted, a PC is refused by the API, and a browser is out of web clock-in until the
 * person signs in again. Restoring lets the device be used again after a new sign-in.
 */
class DeviceController extends Controller
{
    private const PER_PAGE = 25;

    /** How many people per device the list sends; the rest is a count */
    private const PEOPLE_SHOWN = 3;

    public function index(Request $request, DeviceOpenShifts $openShifts, WebDevice $webDevice, Settings $settings): Response
    {
        $filters = $this->filters($request);
        $now = CarbonImmutable::now();
        $prefix = ShiftRules::WEB_DEVICE_PREFIX.'%';

        $devices = Device::query()
            ->when($filters['kind'] === 'browser', fn (Builder $q) => $q->where('id', 'like', $prefix))
            ->when($filters['kind'] === 'desktop', fn (Builder $q) => $q->where('id', 'not like', $prefix))
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->whereNull('revoked_at'))
            ->when($filters['status'] === 'revoked', fn (Builder $q) => $q->whereNotNull('revoked_at'))
            ->when($filters['q'] !== '', function (Builder $query) use ($filters) {
                $like = '%'.addcslashes($filters['q'], '%_\\').'%';
                $query->where(fn (Builder $q) => $q
                    ->where('hostname', 'like', $like)
                    ->orWhere('id', 'like', $like)
                    ->orWhereHas('users', fn (Builder $u) => $u->withTrashed()->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('username', 'like', $like))));
            })
            ->withCount('users')
            ->orderByRaw('revoked_at is null desc')
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $ids = $devices->getCollection()->modelKeys();
        $people = $this->peopleOn($ids);
        $running = $openShifts->on($ids, $now);

        $devices->through(fn (Device $device) => [
            'id' => $device->id,
            'kind' => ShiftRules::isWebDevice($device->id) ? 'browser' : 'desktop',
            'hostname' => $device->hostname,
            'app_version' => $device->app_version,
            'last_seen_at' => Time::iso($device->last_seen_at),
            'revoked_at' => Time::iso($device->revoked_at),
            'people' => $people[$device->id] ?? [],
            'people_count' => $device->users_count,
            'open_shifts' => $running[$device->id] ?? [],
        ]);

        $counts = Device::query()
            ->selectRaw('sum(id like ?) as browser, sum(id not like ?) as desktop', [$prefix, $prefix])
            ->first();

        return Inertia::render('admin/devices/Index', [
            'devices' => $devices,
            'filters' => $filters,
            'counts' => ['desktop' => (int) $counts?->desktop, 'browser' => (int) $counts?->browser],
            'this_browser_device_id' => $webDevice->idFrom($request),
            'resume_window_minutes' => $settings->int('attendance.resume_window_minutes'),
            'revocations' => $this->lastActions($ids),
        ]);
    }

    public function revoke(Request $request, Device $device, DeviceOpenShifts $openShifts, Auditor $auditor): RedirectResponse
    {
        $data = $this->validateReason($request, ['confirm_open_shift' => ['nullable', 'boolean']]);
        $now = CarbonImmutable::now();

        if ($device->isRevoked()) {
            return back();
        }

        // The page warns before this; the server checks again, because a shift can start after the page loaded
        $running = $openShifts->on([$device->id], $now)[$device->id] ?? [];

        if ($running !== [] && ! $request->boolean('confirm_open_shift')) {
            throw ValidationException::withMessages(['confirm_open_shift' => 'open_shift']);
        }

        DB::transaction(function () use ($device, $data, $running, $now, $auditor) {
            $device->forceFill(['revoked_at' => $now])->save();
            $tokens = PersonalAccessToken::query()->where('name', $device->id)->delete();

            $auditor->record('device.revoked', null, [
                ...$this->snapshot($device),
                'status' => 'active',
            ], [
                ...$this->snapshot($device),
                'status' => 'revoked',
                'reason' => $data['reason'],
                'tokens_deleted' => (int) $tokens,
                'open_shifts' => array_map(fn (array $shift) => $shift['name'], $running),
            ]);
        });

        return back();
    }

    public function restore(Request $request, Device $device, Auditor $auditor): RedirectResponse
    {
        $data = $this->validateReason($request);

        if (! $device->isRevoked()) {
            return back();
        }

        DB::transaction(function () use ($device, $data, $auditor) {
            $revokedAt = Time::iso($device->revoked_at);
            $device->forceFill(['revoked_at' => null])->save();

            $auditor->record('device.restored', null, [
                ...$this->snapshot($device),
                'status' => 'revoked',
                'revoked_at' => $revokedAt,
            ], [
                ...$this->snapshot($device),
                'status' => 'active',
                'reason' => $data['reason'],
            ]);
        });

        return back();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{reason: string}
     */
    private function validateReason(Request $request, array $extra = []): array
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            ...$extra,
        ], [
            // Codes, translated by the page
            'reason.required' => 'reason_required',
            'reason.min' => 'reason_short',
            'reason.max' => 'reason_long',
            'reason.string' => 'reason_required',
        ]);

        return ['reason' => trim($data['reason'])];
    }

    /** @return array{device_id: string, hostname: string, kind: string} */
    private function snapshot(Device $device): array
    {
        return [
            'device_id' => $device->id,
            'hostname' => $device->hostname,
            'kind' => ShiftRules::isWebDevice($device->id) ? 'browser' : 'desktop',
        ];
    }

    /**
     * People who signed in on each device, latest online sign-in first.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{id: int, name: string, username: string, last_online_sign_in_at: ?string}>>
     */
    private function peopleOn(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('devices_users')
            ->join('users', 'users.id', '=', 'devices_users.user_id')
            ->whereIn('devices_users.device_id', $ids)
            ->orderByDesc('devices_users.last_online_sign_in_at')
            ->get(['devices_users.device_id', 'users.id', 'users.name', 'users.username', 'devices_users.last_online_sign_in_at']);

        $people = [];

        foreach ($rows as $row) {
            if (count($people[$row->device_id] ?? []) >= self::PEOPLE_SHOWN) {
                continue;
            }

            $people[$row->device_id][] = [
                'id' => (int) $row->id,
                'name' => $row->name,
                'username' => $row->username,
                'last_online_sign_in_at' => $row->last_online_sign_in_at !== null
                    ? Time::iso(CarbonImmutable::parse($row->last_online_sign_in_at, 'UTC'))
                    : null,
            ];
        }

        return $people;
    }

    /**
     * The latest revoke or restore of each device, so the list can say who did it and why.
     *
     * @param  list<string>  $ids
     * @return array<string, array{action: string, at: ?string, actor: ?string, reason: ?string}>
     */
    private function lastActions(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $logs = AuditLog::query()
            ->with('actor:id,name')
            ->whereIn('action', ['device.revoked', 'device.restored'])
            ->where(fn (Builder $q) => collect($ids)->each(fn (string $id) => $q->orWhere('after->device_id', $id)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $last = [];

        foreach ($logs as $log) {
            $id = $log->after['device_id'] ?? null;

            if (! is_string($id) || isset($last[$id])) {
                continue;
            }

            $last[$id] = [
                'action' => $log->action,
                'at' => Time::iso($log->created_at),
                'actor' => $log->actor instanceof User ? $log->actor->name : null,
                'reason' => $log->after['reason'] ?? null,
            ];
        }

        return $last;
    }

    /** @return array{kind: string, q: string, status: string} */
    private function filters(Request $request): array
    {
        $kind = $request->query('jenis', 'desktop');
        $status = $request->query('status', 'all');
        $q = $request->query('q', '');

        return [
            'kind' => in_array($kind, ['desktop', 'browser'], true) ? $kind : 'desktop',
            'status' => in_array($status, ['all', 'active', 'revoked'], true) ? $status : 'all',
            'q' => is_string($q) ? mb_substr(trim($q), 0, 100) : '',
        ];
    }
}
