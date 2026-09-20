<?php

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person with a password issued by Superadmin must set their own before using the web app.
 */
class EnsurePasswordChanged
{
    private const ALLOWED_ROUTES = ['password.edit', 'password.update', 'sign-out', 'preferences.update'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            return redirect()->route('password.edit');
        }

        return $next($request);
    }
}
