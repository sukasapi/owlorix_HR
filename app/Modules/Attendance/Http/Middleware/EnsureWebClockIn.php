<?php

namespace App\Modules\Attendance\Http\Middleware;

use App\Modules\Attendance\Services\WebClock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Setting attendance.web_clock_in off: the web clock endpoints refuse (3.11). */
class EnsureWebClockIn
{
    public function __construct(private readonly WebClock $clock) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->clock->enabled(), 403);

        return $next($request);
    }
}
