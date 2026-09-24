<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Enums\EmploymentType;
use App\Modules\Identity\Models\User;

/**
 * The audit view of a person. Never contains the password or tokens.
 */
class PersonSnapshot
{
    /** @return array{name: string, username: string, email: ?string, employee_code: ?string, employment_type: string, intern_days_per_week: ?int, intern_minutes_per_day: ?int, status: string, roles: list<string>, team_ids: list<int>} */
    public static function of(User $user): array
    {
        return [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'employee_code' => $user->employee_code,
            'employment_type' => $user->employment_type->value,
            'intern_days_per_week' => $user->intern_days_per_week,
            'intern_minutes_per_day' => $user->intern_minutes_per_day,
            'status' => $user->status->value,
            'roles' => self::roles($user),
            'team_ids' => $user->teams()->pluck('teams.id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ];
    }

    /**
     * The intern target columns from the People form: kept only for interns, hours stored as minutes. Empty fields
     * leave the person on the Aturan default.
     *
     * @param  array<string, mixed>  $data
     * @return array{intern_days_per_week: ?int, intern_minutes_per_day: ?int}
     */
    public static function internTarget(EmploymentType $type, array $data): array
    {
        $intern = $type === EmploymentType::Intern;
        $hours = $data['intern_hours_per_day'] ?? null;

        return [
            'intern_days_per_week' => $intern && isset($data['intern_days_per_week']) ? (int) $data['intern_days_per_week'] : null,
            'intern_minutes_per_day' => $intern && $hours !== null && $hours !== '' ? (int) round((float) $hours * 60) : null,
        ];
    }

    /** @return list<string> */
    public static function roles(User $user): array
    {
        return $user->roles()->pluck('name')->sort()->values()->all();
    }
}
