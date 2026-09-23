<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Auth\ImposterSession;
use App\Modules\Overtime\Services\PendingApprovals;
use App\Modules\Projects\Services\TaskInbox;
use App\Modules\Projects\Services\TaskPresenter;
use App\Modules\Projects\Services\TaskTimer;
use App\Modules\Shared\Branding\Branding;
use App\Modules\Shared\Navigation\Navigation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $imposter = app(ImposterSession::class);
        $actor = $imposter->active($request) ? $imposter->actor($request) : null;

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'brand' => fn () => app(Branding::class)->toArray(),
                'timezone' => config('owlorix.display_timezone'),
                'locale' => app()->getLocale(),
            ],
            'auth' => fn () => $user ? [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'display_name' => $user->displayName(),
                    'username' => $user->username,
                    'initials' => $user->initials(),
                    'photo_url' => $user->photoUrl(),
                    'locale' => $user->locale,
                    'theme' => $user->theme,
                    'must_change_password' => $user->must_change_password,
                ],
                'permissions' => collect(Permission::cases())
                    ->filter(fn (Permission $p) => $user->hasPermission($p))
                    ->map(fn (Permission $p) => $p->value)
                    ->values(),
                'imposter' => $actor ? [
                    'active' => true,
                    'actor_name' => $actor->name,
                    'actor_username' => $actor->username,
                ] : null,
            ] : null,
            'nav' => fn () => $user && ! $user->must_change_password ? app(Navigation::class)->forUser($user) : [],
            // Counts shown next to nav items, keyed like the nav item (Q13: badge on web)
            'nav_badges' => fn () => $user && ! $user->must_change_password
                ? (object) array_filter([
                    'approvals' => $user->hasPermission(Permission::ApproveOvertime) ? app(PendingApprovals::class)->countFor($user) : 0,
                    'my_tasks' => $user->hasPermission(Permission::ViewProjects) ? app(TaskInbox::class)->decisionsWaitingFor($user) : 0,
                ])
                : new \stdClass,
            // Running task timer, shown in the top bar on every page
            'task_timer' => fn () => $user && ! $user->must_change_password && $user->hasPermission(Permission::LogActivity)
                ? TaskPresenter::timer(app(TaskTimer::class)->running($user)?->load('task'))
                : null,
            'flash' => fn () => array_filter([
                'status' => $request->session()->get('status'),
                'issued_credentials' => $request->session()->get('issued_credentials'),
            ]),
        ];
    }
}
