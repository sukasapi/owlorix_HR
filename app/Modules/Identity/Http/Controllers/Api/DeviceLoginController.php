<?php

namespace App\Modules\Identity\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Http\ApiError;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Auth\AccountLookup;
use App\Modules\Identity\Auth\LoginThrottle;
use App\Modules\Identity\Models\Device;
use App\Modules\Monitoring\Enums\AccessEvent;
use App\Modules\Monitoring\Services\AccessRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Desktop sign-in (docs/03-architecture.md 4.2). Issues one Sanctum token per person per PC, named with the device id.
 * Failed attempts count per account (LoginThrottle); the plain request limit per IP is high because every studio
 * PC shares one public IP.
 */
class DeviceLoginController extends Controller
{
    private const REQUESTS_PER_MINUTE_PER_IP = 600;

    public function __invoke(Request $request, LoginThrottle $throttle, RateLimiter $limiter, AccessRecorder $access): JsonResponse
    {
        $ipKey = 'device-login:'.$request->ip();

        if ($limiter->tooManyAttempts($ipKey, self::REQUESTS_PER_MINUTE_PER_IP)) {
            $retry = $limiter->availableIn($ipKey);

            return ApiError::response(429, 'too_many_requests', __('auth.throttle', ['seconds' => $retry]), ['Retry-After' => $retry]);
        }

        $limiter->hit($ipKey, 60);

        $data = $request->validate([
            'username' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
            // Ids starting with "web:" belong to browsers (docs/02 3.11)
            'device_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:\-]+$/', 'not_regex:/^web:/i'],
            'hostname' => ['required', 'string', 'max:100'],
            'app_version' => ['required', 'string', 'max:20'],
        ]);

        $identifier = trim($data['username']);
        $user = AccountLookup::find($identifier);
        $account = AccountLookup::throttleKey($identifier, $user);
        $wait = $throttle->secondsUntilAllowed($account, $request->ip());

        // Monitor aktivitas: the account's username, or the typed text masked when it matches no account; never the password
        $failed = fn (AccessEvent $event) => $access->record($event, $request, $user?->id, ['username' => AccountLookup::logName($identifier, $user), 'device_id' => $data['device_id']]);

        if ($wait > 0) {
            $failed(AccessEvent::LockedOut);

            return ApiError::response(429, 'throttled', __('auth.throttle_minutes', ['minutes' => (int) ceil($wait / 60)]), ['Retry-After' => $wait]);
        }

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            $throttle->recordFailure($account, $request->ip());
            $failed(AccessEvent::DeviceSignInFailed);

            throw ValidationException::withMessages(['username' => __('auth.failed')]);
        }

        if (! $user->isActive()) {
            $failed(AccessEvent::DeviceSignInFailed);

            return ApiError::response(403, 'inactive', __('auth.inactive'));
        }

        if (Device::query()->find($data['device_id'])?->isRevoked()) {
            $failed(AccessEvent::DeviceSignInFailed);

            return ApiError::response(403, 'device_revoked', __('auth.device_revoked'));
        }

        $throttle->clear($account);
        $now = CarbonImmutable::now();

        $token = DB::transaction(function () use ($user, $data, $now) {
            $device = Device::query()->updateOrCreate(['id' => $data['device_id']], [
                'hostname' => $data['hostname'],
                'app_version' => $data['app_version'],
                'last_seen_at' => $now,
            ]);

            // 3.8.5: the last online sign-in on this PC allows offline sign-in for a while
            $device->users()->syncWithoutDetaching([$user->id => ['last_online_sign_in_at' => Time::db($now)]]);

            $user->tokens()->where('name', $device->id)->delete();

            return $user->createToken($device->id)->plainTextToken;
        });

        $access->record(AccessEvent::DeviceSignIn, $request, $user->id, ['device_id' => $data['device_id']]);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'server_time' => Time::iso($now),
            'device' => ['id' => $data['device_id'], 'hostname' => $data['hostname']],
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name,
                'email' => $user->email,
                'employee_code' => $user->employee_code,
                'locale' => $user->locale,
                'theme' => $user->theme,
                'initials' => $user->initials(),
                'must_change_password' => $user->must_change_password,
                'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
            ],
        ]);
    }
}
