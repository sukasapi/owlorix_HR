<?php

namespace App\Modules\Identity\Auth;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Str;

/**
 * Sign-in accepts a username or an email. A username can never hold "@" (StorePersonRequest), so the
 * "@" alone decides which column is searched. Used by web and desktop sign-in.
 */
class AccountLookup
{
    public static function find(string $identifier): ?User
    {
        $column = str_contains($identifier, '@') ? 'email' : 'username';

        return User::query()->where($column, $identifier)->first();
    }

    /**
     * Failures count against the username, so attempts on someone's email and username add up to the
     * same lockout. An identifier nobody owns counts against itself.
     */
    public static function throttleKey(string $identifier, ?User $user): string
    {
        return $user?->username ?? Str::lower($identifier);
    }
}
