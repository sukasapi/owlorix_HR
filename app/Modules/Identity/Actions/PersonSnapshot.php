<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;

/**
 * The audit view of a person. Never contains the password or tokens.
 */
class PersonSnapshot
{
    /** @return array{name: string, username: string, email: ?string, employee_code: ?string, employment_type: string, status: string, roles: list<string>, team_ids: list<int>} */
    public static function of(User $user): array
    {
        return [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'employee_code' => $user->employee_code,
            'employment_type' => $user->employment_type->value,
            'status' => $user->status->value,
            'roles' => self::roles($user),
            'team_ids' => $user->teams()->pluck('teams.id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ];
    }

    /** @return list<string> */
    public static function roles(User $user): array
    {
        return $user->roles()->pluck('name')->sort()->values()->all();
    }
}
