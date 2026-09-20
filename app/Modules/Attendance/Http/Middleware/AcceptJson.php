<?php

namespace App\Modules\Attendance\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Desktop API responses and errors are always JSON, whatever Accept header the app sends. */
class AcceptJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
