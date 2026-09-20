<?php

namespace App\Modules\Attendance\Http\Middleware;

use App\Modules\Attendance\Http\ApiError;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a desktop device token (Sanctum, named with the device id), refuses revoked devices and inactive
 * people, and limits requests per token. The limit is per token, not per IP, because every studio PC shares one
 * public IP.
 *
 * Authentication happens here instead of in `auth:sanctum` so it runs after AcceptJson: Laravel moves its own
 * auth middleware to the front of the stack, where a failed check would answer with a redirect.
 */
class AuthenticateDevice
{
    public const DEVICE = 'desktop_device';

    /** A PC syncs every 120 s, plus retries, shift reads and config reads */
    private const REQUESTS_PER_MINUTE = 120;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly RateLimiter $limiter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->auth->guard('sanctum')->user();
        $token = $user?->currentAccessToken();

        if (! $user instanceof User || ! $token instanceof PersonalAccessToken) {
            return ApiError::response(401, 'unauthenticated', __('auth.sign_in_first'));
        }

        $this->auth->shouldUse('sanctum');

        $device = Device::query()->find($token->name);

        if ($device === null || $device->isRevoked()) {
            $token->delete();

            return ApiError::response(401, 'device_revoked', __('auth.device_revoked'));
        }

        if (! $user->isActive()) {
            return ApiError::response(403, 'inactive', __('auth.inactive'));
        }

        $key = 'desktop-api:'.$token->getKey();

        if ($this->limiter->tooManyAttempts($key, self::REQUESTS_PER_MINUTE)) {
            $retry = $this->limiter->availableIn($key);

            return ApiError::response(429, 'too_many_requests', __('auth.throttle', ['seconds' => $retry]), ['Retry-After' => $retry]);
        }

        $this->limiter->hit($key, 60);
        $request->attributes->set(self::DEVICE, $device);

        return $next($request);
    }

    public static function device(Request $request): Device
    {
        return $request->attributes->get(self::DEVICE);
    }
}
