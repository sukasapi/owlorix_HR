<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Calculation\ShiftRules;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Each browser is a device for attendance (3.11). Its id is `web:` plus a random id kept in a long-lived httpOnly
 * cookie, so one browser keeps one device row across sign-ins; the hostname is a short browser description.
 */
class WebDevice
{
    public const COOKIE = 'owlorix_web_device';

    /** Session key: when this session signed in on the web, in epoch milliseconds */
    public const SIGNED_IN_AT = 'web_device_signed_in_at';

    /** Shown as the clock error; not a code, so Hari ini shows it as written */
    public const REVOKED_MESSAGE = 'Superadmin mencabut akses absen dari browser ini. Keluar dari web, lalu masuk lagi untuk absen dari sini.';

    /** Five years: the id only has to outlive normal use of one browser */
    private const LIFETIME_MINUTES = 60 * 24 * 365 * 5;

    /** The device id this browser already has, without creating one. */
    public function idFrom(Request $request): ?string
    {
        $value = $request->cookie(self::COOKIE);

        return is_string($value) && preg_match('/^web:[A-Za-z0-9]{32}$/', $value) === 1 ? $value : null;
    }

    /** The saved browser device of this request, without creating or updating it; null when unknown or revoked. */
    public function find(Request $request): ?Device
    {
        $id = $this->idFrom($request);
        $device = $id !== null ? Device::query()->find($id) : null;

        return $device !== null && self::isBrowser($device) && ! $device->isRevoked() ? $device : null;
    }

    /** The device row of this browser, created on first use and linked to the person. */
    public function resolve(Request $request, User $user, ?CarbonImmutable $now = null): Device
    {
        $now ??= CarbonImmutable::now();
        $id = $this->idFrom($request);
        $existing = $id !== null ? Device::query()->find($id) : null;

        // An id already used by a desktop app is never taken over by a browser
        if ($id === null || ($existing !== null && ! self::isBrowser($existing))) {
            $id = ShiftRules::WEB_DEVICE_PREFIX.Str::random(32);
            $existing = null;
        }

        // A revoked browser stays out of web clock-in for the sign-in it had when it was revoked. Signing in again
        // after the revoke gives this browser a new id; the revoked row keeps its history.
        if ($existing?->isRevoked()) {
            if (! self::signedInAfter($request, $existing->revoked_at)) {
                throw WebClock::refuse(self::REVOKED_MESSAGE);
            }

            $id = ShiftRules::WEB_DEVICE_PREFIX.Str::random(32);
            $existing = null;
        }

        $device = Device::query()->updateOrCreate(['id' => $id], [
            'hostname' => self::describe($request->userAgent()),
            'app_version' => 'web',
            'last_seen_at' => $now,
        ]);

        $device->users()->syncWithoutDetaching([$user->id => ['last_online_sign_in_at' => Time::db($now)]]);

        Cookie::queue(Cookie::make(self::COOKIE, $id, self::LIFETIME_MINUTES, '/', null, null, true, false, 'lax'));

        return $device;
    }

    /** Called at web sign-in, so a browser revoked before this moment can get a new id (see resolve). */
    public static function rememberSignIn(Request $request): void
    {
        $request->session()->put(self::SIGNED_IN_AT, CarbonImmutable::now()->getTimestampMs());
    }

    private static function signedInAfter(Request $request, CarbonImmutable $revokedAt): bool
    {
        $signedInAt = $request->hasSession() ? $request->session()->get(self::SIGNED_IN_AT) : null;

        return is_int($signedInAt) && $signedInAt > $revokedAt->getTimestampMs();
    }

    private static function isBrowser(Device $device): bool
    {
        return $device->app_version === 'web' && ShiftRules::isWebDevice($device->id);
    }

    /** "Browser: Chrome, Windows" from a user agent; only the browser family and the operating system are kept. */
    public static function describe(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS/') => 'Firefox',
            str_contains($ua, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser lain',
        };

        $system = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return Str::limit('Browser: '.$browser.($system !== null ? ', '.$system : ''), 100, '');
    }
}
