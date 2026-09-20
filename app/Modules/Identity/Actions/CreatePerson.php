<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Auth\TemporaryPassword;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Actions\TeamMembership;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Support\Facades\DB;

class CreatePerson
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly TeamMembership $teams,
    ) {}

    /**
     * @param  array{name: string, username: string, email?: ?string, employee_code?: ?string, roles: list<string>, team_ids?: ?list<int>}  $data
     * @return array{user: User, password: string}
     */
    public function __invoke(array $data): array
    {
        $password = TemporaryPassword::generate();

        $user = DB::transaction(function () use ($data, $password) {
            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'employee_code' => $data['employee_code'] ?? null,
                'password' => $password,
                'must_change_password' => true,
                'password_changed_at' => null,
                'status' => UserStatus::Active,
            ])->save();

            $user->syncRoles($data['roles']);
            $this->teams->syncForPerson($user, $data['team_ids'] ?? []);

            $this->auditor->record('user.created', $user, null, PersonSnapshot::of($user));

            return $user;
        });

        return ['user' => $user, 'password' => $password];
    }
}
