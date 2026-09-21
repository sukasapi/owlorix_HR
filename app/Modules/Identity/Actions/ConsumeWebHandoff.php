<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Attendance\Services\WebDevice;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\WebHandoff;
use App\Modules\Shared\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Turns a one-time handoff token into a web session. Returns null when the token is unknown,
 * already used, expired, or the account is no longer active.
 */
class ConsumeWebHandoff
{
    public function __construct(private readonly Auditor $auditor) {}

    public function handle(Request $request, string $plainToken): ?User
    {
        $hash = hash('sha256', $plainToken);
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($request, $hash, $now) {
            $handoff = WebHandoff::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($handoff === null || $handoff->used_at !== null || $handoff->expires_at->lte($now)) {
                return null;
            }

            $user = User::query()->find($handoff->user_id);

            if ($user === null || ! $user->isActive()) {
                $handoff->forceFill(['used_at' => $now])->save();

                return null;
            }

            $handoff->forceFill(['used_at' => $now])->save();

            if (Auth::guard('web')->check() && Auth::guard('web')->id() !== $user->id) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            WebDevice::rememberSignIn($request);

            $this->auditor->record('auth.web_handoff_used', $user, null, [
                'device_id' => $handoff->device_id,
                'handoff_id' => $handoff->id,
            ], $user->id);

            return $user;
        });
    }
}
