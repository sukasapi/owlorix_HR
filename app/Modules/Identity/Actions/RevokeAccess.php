<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signs a person out everywhere: desktop device tokens (Sanctum) and web sessions stored in the database.
 */
class RevokeAccess
{
    /** @return array{tokens: int, sessions: int} */
    public function __invoke(User $user): array
    {
        $tokens = $user->tokens()->delete();
        $sessions = DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();

        $user->forceFill(['remember_token' => Str::random(60)])->save();

        return ['tokens' => (int) $tokens, 'sessions' => (int) $sessions];
    }
}
