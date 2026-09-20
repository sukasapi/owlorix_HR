<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Access\Permission;
use App\Modules\Overtime\Services\PendingApprovals;
use App\Modules\Shared\Navigation\Navigation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'timezone' => config('owlorix.display_timezone'),
                'locale' => app()->getLocale(),
            ],
            'auth' => fn () => $user ? [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'initials' => $user->initials(),
                    'locale' => $user->locale,
                    'theme' => $user->theme,
                    'must_change_password' => $user->must_change_password,
                ],
                'permissions' => collect(Permission::cases())
                    ->filter(fn (Permission $p) => $user->hasPermission($p))
                    ->map(fn (Permission $p) => $p->value)
                    ->values(),
            ] : null,
            'nav' => fn () => $user && ! $user->must_change_password ? app(Navigation::class)->forUser($user) : [],
            // Counts shown next to nav items, keyed like the nav item (Q13: badge on web)
            'nav_badges' => fn () => $user && ! $user->must_change_password && $user->hasPermission(Permission::ApproveOvertime)
                ? ['approvals' => app(PendingApprovals::class)->countFor($user)]
                : new \stdClass,
            'flash' => fn () => array_filter([
                'status' => $request->session()->get('status'),
                'issued_credentials' => $request->session()->get('issued_credentials'),
            ]),
        ];
    }
}
