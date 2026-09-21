<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\EmploymentType;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Http\Requests\PeopleMessages;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Actions\TeamMembership;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePerson
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly TeamMembership $teams,
        private readonly RevokeAccess $revoke,
    ) {}

    /**
     * @param  array{name: string, email?: ?string, employee_code?: ?string, employment_type: string, roles: list<string>, team_ids?: ?list<int>, status: string}  $data
     *
     * @throws ValidationException when a Superadmin guard blocks the change
     */
    public function __invoke(User $actor, User $user, array $data): User
    {
        return DB::transaction(function () use ($actor, $user, $data) {
            // Lock every active Superadmin row so two admins cannot demote each other at the same moment.
            $activeSuperadminIds = User::role(Role::Superadmin->value)->active()->lockForUpdate()->pluck('users.id')->map(fn ($id) => (int) $id);
            $user->refresh();

            $before = PersonSnapshot::of($user);
            $roles = collect($data['roles'])->unique()->sort()->values()->all();
            $status = UserStatus::from($data['status']);
            $employmentType = EmploymentType::from($data['employment_type']);

            $this->guard($actor, $user, $roles, $status, $activeSuperadminIds->all());

            $user->forceFill([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'employee_code' => $data['employee_code'] ?? null,
                'employment_type' => $employmentType,
                'status' => $status,
            ])->save();

            $user->syncRoles($roles);
            $this->teams->syncForPerson($user, $data['team_ids'] ?? []);
            $this->teams->releaseLeadsWithoutRole($user);

            $revoked = null;
            if ($status !== UserStatus::Active) {
                $revoked = ($this->revoke)($user);
            }

            $after = PersonSnapshot::of($user);
            $this->audit($user, $before, $after, $revoked);

            return $user;
        });
    }

    /**
     * @param  list<string>  $roles
     * @param  list<int>  $activeSuperadminIds
     */
    private function guard(User $actor, User $user, array $roles, UserStatus $status, array $activeSuperadminIds): void
    {
        $isActiveSuperadmin = in_array($user->id, $activeSuperadminIds, true);
        $losesRole = $user->hasRole(Role::Superadmin->value) && ! in_array(Role::Superadmin->value, $roles, true);
        $deactivates = $user->status === UserStatus::Active && $status !== UserStatus::Active;
        $errors = [];

        if ($actor->is($user)) {
            if ($deactivates) {
                $errors['status'] = PeopleMessages::get('self_status');
            }
            if ($losesRole) {
                $errors['roles'] = PeopleMessages::get('self_role');
            }
        }

        $isLast = $isActiveSuperadmin && count($activeSuperadminIds) === 1;

        if ($isLast && $deactivates && ! isset($errors['status'])) {
            $errors['status'] = PeopleMessages::get('last_superadmin_status');
        }
        if ($isLast && $losesRole && ! isset($errors['roles'])) {
            $errors['roles'] = PeopleMessages::get('last_superadmin_role');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array{tokens: int, sessions: int}|null  $revoked
     */
    private function audit(User $user, array $before, array $after, ?array $revoked): void
    {
        if ($before['roles'] !== $after['roles']) {
            $this->auditor->record('user.roles_changed', $user, ['roles' => $before['roles']], ['roles' => $after['roles']]);
        }

        if ($before['status'] !== $after['status']) {
            $this->auditor->record('user.status_changed', $user, ['status' => $before['status']], [
                'status' => $after['status'],
                'tokens_revoked' => $revoked['tokens'] ?? 0,
                'sessions_revoked' => $revoked['sessions'] ?? 0,
            ]);
        }

        $changed = collect(['name', 'email', 'employee_code', 'employment_type', 'team_ids'])
            ->filter(fn (string $key) => $before[$key] !== $after[$key])
            ->values();

        if ($changed->isNotEmpty()) {
            $this->auditor->record(
                'user.updated',
                $user,
                $changed->mapWithKeys(fn ($key) => [$key => $before[$key]])->all(),
                $changed->mapWithKeys(fn ($key) => [$key => $after[$key]])->all(),
            );
        }
    }
}
