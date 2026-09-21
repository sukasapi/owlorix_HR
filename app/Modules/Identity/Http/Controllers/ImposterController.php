<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Auth\ImposterSession;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Http\Requests\ImposterMessages;
use App\Modules\Identity\Http\Requests\PeopleMessages;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ImposterController extends Controller
{
    public function __construct(private readonly ImposterSession $imposter) {}

    public function index(Request $request): Response
    {
        if ($this->imposter->active($request)) {
            abort(403, PeopleMessages::get('imposter_blocked'));
        }

        $people = User::query()
            ->active()
            ->with('roles:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->reject(fn (User $user) => $user->hasRole(Role::Superadmin->value) || $user->is($request->user()))
            ->values()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'initials' => $user->initials(),
                'roles' => $user->roles->pluck('name')->sort()->values()->all(),
            ]);

        return Inertia::render('imposter/Index', [
            'people' => $people,
        ]);
    }

    public function start(Request $request, User $user, Auditor $auditor): RedirectResponse
    {
        $actor = $request->user();

        if ($this->imposter->active($request)) {
            throw ValidationException::withMessages(['user' => PeopleMessages::get('imposter_blocked')]);
        }

        if (! $actor?->hasPermission(Permission::ImpersonateUsers)) {
            abort(403);
        }

        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => ImposterMessages::get('self')]);
        }

        if ($user->status !== UserStatus::Active) {
            throw ValidationException::withMessages(['user' => ImposterMessages::get('inactive')]);
        }

        if ($user->hasRole(Role::Superadmin->value)) {
            throw ValidationException::withMessages(['user' => ImposterMessages::get('superadmin')]);
        }

        $auditor->record(
            'auth.imposter_started',
            $user,
            null,
            ['target_id' => $user->id, 'target_username' => $user->username],
            actorId: $actor->id,
        );

        $this->imposter->start($request, $actor, $user);

        return redirect()->route('my-day')->with('status', ImposterMessages::get('started', ['name' => $user->name]));
    }

    public function stop(Request $request, Auditor $auditor): RedirectResponse
    {
        if (! $this->imposter->active($request)) {
            return redirect()->route('my-day');
        }

        $target = $request->user();
        $actor = $this->imposter->stop($request);

        if ($actor !== null && $target !== null) {
            $auditor->record(
                'auth.imposter_stopped',
                $target,
                null,
                ['target_id' => $target->id, 'target_username' => $target->username],
                actorId: $actor->id,
            );
        }

        return redirect()->route('imposter.index')->with('status', ImposterMessages::get('stopped'));
    }
}
