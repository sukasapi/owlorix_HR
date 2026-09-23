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
    /** Joins the visible start and the hash of an identifier nobody owns; a username never holds "*" or "#" */
    public const MASK = '***#';

    public static function find(string $identifier): ?User
    {
        $column = str_contains($identifier, '@') ? 'email' : 'username';

        return User::query()->where($column, $identifier)->first();
    }

    /**
     * Failures count against the username, so attempts on someone's email and username add up to the
     * same lockout. An identifier nobody owns counts against a keyed hash of itself: the key sits in the cache
     * for up to a day, and the text may be a password typed into the wrong box.
     */
    public static function throttleKey(string $identifier, ?User $user): string
    {
        return $user?->username ?? 'unknown#'.hash_hmac('sha256', Str::lower(trim($identifier)), (string) config('app.key'));
    }

    /**
     * What access_logs keeps of a failed sign-in: the account's username, or for an identifier nobody owns (it may
     * be a password typed into the wrong box) at most its first two characters and a keyed hash, so repeated tries
     * of the same text still group in "Perlu dicek" while the text itself is never stored.
     */
    public static function logName(string $identifier, ?User $user): string
    {
        if ($user !== null) {
            return $user->username;
        }

        $typed = Str::lower(trim($identifier));
        // Never more than a third of what was typed
        $shown = mb_substr($typed, 0, min(2, intdiv(mb_strlen($typed), 3)));

        return $shown.self::MASK.substr(hash_hmac('sha256', $typed, (string) config('app.key')), 0, 8);
    }

    /** The visible start of a masked identifier (possibly empty), or null when the value is a real username. */
    public static function maskedPrefix(?string $logged): ?string
    {
        if ($logged === null || ! str_contains($logged, self::MASK)) {
            return null;
        }

        return strstr($logged, self::MASK, true);
    }
}
