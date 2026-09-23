<?php

namespace App\Modules\Monitoring\Services;

use App\Modules\Attendance\Support\Time;
use App\Modules\Monitoring\Enums\AccessEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes one access_logs row with a single insert. Never stores query strings, form bodies, or passwords, and never
 * throws: a failed write is reported and the request carries on.
 */
class AccessRecorder
{
    /** Routes whose path holds a secret (a one-time token): the route pattern is stored instead */
    private const PATTERN_ONLY = ['sign-in.desktop-handoff'];

    /**
     * @param  array{username?: ?string, device_id?: ?string, status?: ?int}  $extra
     */
    public function record(AccessEvent $event, Request $request, ?int $userId = null, array $extra = []): void
    {
        try {
            $route = $request->route();
            $name = is_object($route) ? $route->getName() : null;

            DB::table('access_logs')->insert([
                'user_id' => $userId,
                'event' => $event->value,
                'method' => substr($request->getMethod(), 0, 8),
                'route_name' => $name !== null ? mb_substr($name, 0, 120) : null,
                'path' => mb_substr(is_object($route) && in_array($name, self::PATTERN_ONLY, true) ? '/'.ltrim($route->uri(), '/') : '/'.ltrim($request->path(), '/'), 0, 255),
                'status' => $extra['status'] ?? null,
                'username' => isset($extra['username']) ? mb_substr($extra['username'], 0, 100) : null,
                'device_id' => isset($extra['device_id']) ? mb_substr($extra['device_id'], 0, 64) : null,
                'ip' => $request->ip(),
                'user_agent' => ($agent = $request->userAgent()) !== null && $agent !== '' ? mb_substr($agent, 0, 255) : null,
                'created_at' => Time::db(CarbonImmutable::now()),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
