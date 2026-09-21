<?php

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Actions\CreatePerson;
use App\Modules\Identity\Actions\ResetPersonPassword;
use App\Modules\Identity\Actions\UpdatePerson;
use App\Modules\Identity\Auth\ImposterSession;
use App\Modules\Identity\Enums\EmploymentType;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Http\Requests\PeopleMessages;
use App\Modules\Identity\Http\Requests\StorePersonRequest;
use App\Modules\Identity\Http\Requests\UpdatePersonRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Orang: Superadmin creates accounts, issues temporary passwords, and assigns roles, teams, and status.
 */
class PeopleController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $people = User::query()
            ->with(['roles:id,name', 'teams:id,name'])
            ->when($filters['q'] !== '', function (Builder $query) use ($filters) {
                $like = '%'.addcslashes($filters['q'], '%_\\').'%';
                $query->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('username', 'like', $like));
            })
            ->when($filters['status'] !== 'all', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['team'] === 'none', fn (Builder $query) => $query->whereDoesntHave('teams'))
            ->when(is_int($filters['team']), fn (Builder $query) => $query->whereHas('teams', fn (Builder $q) => $q->whereKey($filters['team'])))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'initials' => $user->initials(),
                'email' => $user->email,
                'employee_code' => $user->employee_code,
                'employment_type' => $user->employment_type->value,
                'status' => $user->status->value,
                'roles' => $this->orderedRoles($user),
                'team_ids' => $user->teams->sortBy('name')->pluck('id')->values(),
            ]);

        return Inertia::render('admin/people/Index', [
            'people' => $people,
            'filters' => $filters,
            'teams' => Team::query()->orderBy('name')->get(['id', 'name']),
            'roles' => array_map(fn (Role $role) => $role->value, Role::cases()),
            'statuses' => array_map(fn (UserStatus $status) => $status->value, UserStatus::cases()),
            'employment_types' => array_map(fn (EmploymentType $type) => $type->value, EmploymentType::cases()),
        ]);
    }

    public function store(StorePersonRequest $request, CreatePerson $create, ImposterSession $imposter): RedirectResponse
    {
        $this->refuseWhileImpostering($request, $imposter);

        ['user' => $user, 'password' => $password] = $create($request->validated());

        return back()->with('issued_credentials', [
            'name' => $user->name,
            'username' => $user->username,
            'password' => $password,
        ]);
    }

    public function update(UpdatePersonRequest $request, User $user, UpdatePerson $update, ImposterSession $imposter): RedirectResponse
    {
        $this->refuseWhileImpostering($request, $imposter);

        $roles = $request->validated('roles');
        if (in_array(Role::Superadmin->value, $roles, true) || $user->hasRole(Role::Superadmin->value)) {
            $this->refuseWhileImpostering($request, $imposter);
        }

        $update($request->user(), $user, $request->validated());

        return back()->with('status', PeopleMessages::get('saved', ['name' => $user->name]));
    }

    public function resetPassword(Request $request, User $user, ResetPersonPassword $reset, ImposterSession $imposter): RedirectResponse
    {
        $this->refuseWhileImpostering($request, $imposter);

        $password = $reset($user);

        return back()->with('issued_credentials', [
            'name' => $user->name,
            'username' => $user->username,
            'password' => $password,
        ]);
    }

    private function refuseWhileImpostering(Request $request, ImposterSession $imposter): void
    {
        if ($imposter->active($request)) {
            throw ValidationException::withMessages(['imposter' => PeopleMessages::get('imposter_blocked')]);
        }
    }

    /** @return array{q: string, status: string, team: int|string} */
    private function filters(Request $request): array
    {
        $status = $request->query('status', UserStatus::Active->value);
        $team = $request->query('team', 'all');
        $q = $request->query('q', '');

        return [
            'q' => is_string($q) ? mb_substr(trim($q), 0, 100) : '',
            'status' => in_array($status, ['all', ...array_column(UserStatus::cases(), 'value')], true) ? $status : UserStatus::Active->value,
            'team' => match (true) {
                $team === 'none' => 'none',
                is_string($team) && ctype_digit($team) => (int) $team,
                default => 'all',
            },
        ];
    }

    /** @return list<string> Roles in the order the Role enum declares them. */
    private function orderedRoles(User $user): array
    {
        $held = $user->roles->pluck('name')->all();

        return array_values(array_filter(array_map(fn (Role $r) => $r->value, Role::cases()), fn ($value) => in_array($value, $held, true)));
    }
}
