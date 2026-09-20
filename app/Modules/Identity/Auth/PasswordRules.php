<?php

namespace App\Modules\Identity\Auth;

use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    public static function default(): Password
    {
        return Password::min(config('owlorix.password_min_length'))->max(255);
    }
}
