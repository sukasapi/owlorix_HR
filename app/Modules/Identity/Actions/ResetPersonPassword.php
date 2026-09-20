<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Auth\TemporaryPassword;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Support\Facades\DB;

class ResetPersonPassword
{
    public function __construct(private readonly Auditor $auditor) {}

    /** Returns the new temporary password. It is shown once and never stored in plain text. */
    public function __invoke(User $user): string
    {
        $password = TemporaryPassword::generate();

        DB::transaction(function () use ($user, $password) {
            $before = ['must_change_password' => $user->must_change_password];

            $user->forceFill([
                'password' => $password,
                'must_change_password' => true,
                'password_changed_at' => null,
            ])->save();

            $this->auditor->record('user.password_reset', $user, $before, ['must_change_password' => true]);
        });

        return $password;
    }
}
