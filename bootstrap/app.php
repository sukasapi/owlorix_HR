<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Identity\Http\Middleware\EnsureImposterEnabled;
use App\Modules\Identity\Http\Middleware\EnsurePasswordChanged;
use App\Modules\Identity\Http\Middleware\EnsureUserIsActive;
use App\Modules\Identity\Http\Middleware\SetLocale;
use App\Modules\Monitoring\Http\Middleware\RecordAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Spatie\Permission\Middleware\PermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            EnsureUserIsActive::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            RecordAccess::class,
        ]);

        // Monitor aktivitas must wrap the auth and throttle route middleware, which Laravel otherwise sorts in front
        // of it, or their refusals (429) would never reach it
        $middleware->appendToPriorityList(ShareErrorsFromSession::class, RecordAccess::class);

        $middleware->alias([
            'password.changed' => EnsurePasswordChanged::class,
            'permission' => PermissionMiddleware::class,
            'imposter.enabled' => EnsureImposterEnabled::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('sign-in'));
        $middleware->redirectUsersTo(fn () => route('my-day'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The desktop app always gets JSON errors, never a redirect to the web sign-in page.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
