<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Auth\ImposterSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Returns 404 when IMPOSTER_MODE is off so the routes do not exist in production by default. */
class EnsureImposterEnabled
{
    public function __construct(private readonly ImposterSession $imposter) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->imposter->enabled()) {
            abort(404);
        }

        return $next($request);
    }
}
