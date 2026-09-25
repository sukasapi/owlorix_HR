<?php

namespace App\Modules\Monitoring\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Http\Middleware\AuthenticateDevice;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Models\User;
use App\Modules\Monitoring\Models\AppUsageSession;
use App\Modules\Monitoring\Services\AppUsagePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Receives application sessions from the desktop app (Aktivitas detail). A session is kept only when the feature is
 * on, the person's employment type is recorded, and it overlaps one of their shifts: the server, not only the app,
 * makes sure nothing outside work time or outside the chosen types is stored. Every id is answered once (accepted,
 * duplicate, or rejected with a code), so the app stops resending it.
 */
class AppUsageUploadController extends Controller
{
    private const MAX_SESSION_SECONDS = 12 * 3600;

    public function __invoke(Request $request, AppUsagePolicy $policy): JsonResponse
    {
        $data = $request->validate([
            'sessions' => ['present', 'array', 'max:500'],
            'sessions.*.id' => ['required', 'uuid'],
            'sessions.*.app' => ['required', 'string', 'max:120'],
            'sessions.*.exe' => ['required', 'string', 'max:160'],
            'sessions.*.title' => ['nullable', 'string', 'max:255'],
            'sessions.*.browser' => ['required', 'boolean'],
            'sessions.*.started_at' => ['required', 'date'],
            'sessions.*.ended_at' => ['required', 'date'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $device = $request->attributes->get(AuthenticateDevice::DEVICE);

        $enabled = $policy->enabled();
        $recorded = $policy->records($user);
        $ids = array_column($data['sessions'], 'id');
        $known = AppUsageSession::query()->whereIn('client_id', $ids)->pluck('client_id')
            ->merge(DB::table('app_usage_archive')->whereIn('client_id', $ids)->pluck('client_id'))
            ->flip();

        $accepted = [];
        $duplicates = [];
        $rejected = [];

        foreach ($data['sessions'] as $session) {
            $id = $session['id'];
            if ($known->has($id)) {
                $duplicates[] = $id;

                continue;
            }

            $code = $this->refusal($session, $enabled, $recorded);
            if ($code !== null) {
                $rejected[] = ['id' => $id, 'code' => $code];

                continue;
            }

            $started = CarbonImmutable::parse($session['started_at']);
            $ended = CarbonImmutable::parse($session['ended_at']);
            $shift = $this->shiftFor($user, $started, $ended);

            if ($shift === null) {
                $rejected[] = ['id' => $id, 'code' => 'outside_shift'];

                continue;
            }

            AppUsageSession::query()->create([
                'client_id' => $id,
                'user_id' => $user->id,
                'shift_id' => $shift->id,
                'device_id' => $device?->id ?? '',
                'app_name' => $session['app'],
                'exe' => $session['exe'],
                'window_title' => $this->title($session['title'] ?? null),
                'is_browser' => (bool) $session['browser'],
                'started_at' => $started,
                'ended_at' => $ended,
                'seconds' => $ended->getTimestamp() - $started->getTimestamp(),
            ]);
            $known->put($id, true);
            $accepted[] = $id;
        }

        return response()->json([
            'enabled' => $enabled,
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
        ]);
    }

    /** @param array<string, mixed> $session */
    private function refusal(array $session, bool $enabled, bool $recorded): ?string
    {
        if (! $enabled) {
            return 'disabled';
        }
        if (! $recorded) {
            return 'employment_type';
        }

        $started = CarbonImmutable::parse($session['started_at']);
        $ended = CarbonImmutable::parse($session['ended_at']);
        $seconds = $ended->getTimestamp() - $started->getTimestamp();

        if ($seconds <= 0 || $seconds > self::MAX_SESSION_SECONDS || $ended->gt(now()->addMinutes(5))) {
            return 'invalid_time';
        }

        return null;
    }

    /** The person's shift that overlaps the session; an open shift counts until now. */
    private function shiftFor(User $user, CarbonImmutable $started, CarbonImmutable $ended): ?Shift
    {
        return Shift::query()
            ->where('user_id', $user->id)
            ->where('clock_in_at', '<=', $ended)
            ->where(fn ($q) => $q->whereNull('clock_out_at')->orWhere('clock_out_at', '>=', $started))
            ->orderByDesc('clock_in_at')
            ->first();
    }

    private function title(?string $title): ?string
    {
        $title = $title === null ? null : trim(preg_replace('/\s+/u', ' ', $title) ?? '');

        return $title === '' ? null : mb_substr($title, 0, 255);
    }
}
