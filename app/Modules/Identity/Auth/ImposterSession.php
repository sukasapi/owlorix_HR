<?php

namespace App\Modules\Identity\Auth;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * Tracks when a Superadmin is signed in as someone else (session key `imposter_id`).
 */
class ImposterSession
{
    public const KEY = 'imposter_id';

    public function enabled(): bool
    {
        return (bool) config('owlorix.imposter.enabled');
    }

    public function active(Request $request): bool
    {
        return $this->enabled() && $request->session()->has(self::KEY);
    }

    public function actorId(Request $request): ?int
    {
        $id = $request->session()->get(self::KEY);

        return is_numeric($id) ? (int) $id : null;
    }

    public function actor(Request $request): ?User
    {
        $id = $this->actorId($request);

        return $id === null ? null : User::query()->find($id);
    }

    public function start(Request $request, User $actor, User $target): void
    {
        $request->session()->put(self::KEY, $actor->id);
        auth()->login($target);
        $request->session()->regenerate();
    }

    public function stop(Request $request): ?User
    {
        $actor = $this->actor($request);
        $request->session()->forget(self::KEY);

        if ($actor !== null) {
            auth()->login($actor);
            $request->session()->regenerate();
        }

        return $actor;
    }

    public function clear(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }
}
