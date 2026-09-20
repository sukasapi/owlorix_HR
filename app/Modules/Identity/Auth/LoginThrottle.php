<?php

namespace App\Modules\Identity\Auth;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * Counts failed sign-ins per username with growing waits (5 failures, then 1, 5, 15 minutes).
 * The per-IP limit is high because every studio PC shares one public IP. Used by web and desktop sign-in.
 */
class LoginThrottle
{
    public function __construct(
        private readonly Cache $cache,
        private readonly RateLimiter $limiter,
    ) {}

    /** Seconds left before this username or IP may try again; 0 when allowed. */
    public function secondsUntilAllowed(string $username, ?string $ip): int
    {
        $lockedUntil = (int) $this->cache->get($this->lockKey($username), 0);
        $userWait = max(0, $lockedUntil - now()->getTimestamp());

        $ipWait = $ip !== null && $this->limiter->tooManyAttempts($this->ipKey($ip), config('owlorix.login.ip_max_failures'))
            ? $this->limiter->availableIn($this->ipKey($ip))
            : 0;

        return max($userWait, $ipWait);
    }

    public function recordFailure(string $username, ?string $ip): void
    {
        $step = config('owlorix.login.failures_per_step');
        $waits = config('owlorix.login.wait_minutes');

        $failures = (int) $this->cache->get($this->countKey($username), 0) + 1;
        $this->cache->put($this->countKey($username), $failures, now()->addDay());

        if ($failures % $step === 0) {
            $minutes = $waits[min(intdiv($failures, $step), count($waits)) - 1];
            $this->cache->put($this->lockKey($username), now()->addMinutes($minutes)->getTimestamp(), now()->addMinutes($minutes));
        }

        if ($ip !== null) {
            $this->limiter->hit($this->ipKey($ip), config('owlorix.login.ip_decay_minutes') * 60);
        }
    }

    public function clear(string $username): void
    {
        $this->cache->forget($this->countKey($username));
        $this->cache->forget($this->lockKey($username));
    }

    private function countKey(string $username): string
    {
        return 'login:failures:'.Str::lower($username);
    }

    private function lockKey(string $username): string
    {
        return 'login:locked-until:'.Str::lower($username);
    }

    private function ipKey(string $ip): string
    {
        return 'login:ip:'.$ip;
    }
}
