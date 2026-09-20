<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Calendar\Models\WorkWeekDay;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rules for Management opening extra workdays (docs/02-attendance-rules.md 3.2.2, proposal for Q17):
 * a Team Lead opens dates for the teams they lead and for members of those teams;
 * Project Managers, Project Directors, and Superadmin open dates for any team or person.
 */
class WorkdayOpening
{
    /** @var array<int, OpeningRights> */
    private array $rights = [];

    public function __construct(private readonly WorkdayResolver $resolver) {}

    public function rightsFor(User $user): OpeningRights
    {
        return $this->rights[$user->getKey()] ??= $this->resolveRights($user);
    }

    /** @return Collection<int, Team> */
    public function teamsFor(User $user): Collection
    {
        $rights = $this->rightsFor($user);

        if ($rights->isEmpty()) {
            return collect();
        }

        return Team::query()
            ->when(! $rights->any, fn ($q) => $q->whereIn('id', $rights->teamIds))
            ->withCount('members')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, User> */
    public function peopleFor(User $user): Collection
    {
        $rights = $this->rightsFor($user);

        if ($rights->isEmpty()) {
            return collect();
        }

        return User::query()
            ->active()
            ->when(! $rights->any, fn ($q) => $q->whereIn('id', $rights->userIds))
            ->orderBy('name')
            ->get(['id', 'name', 'username']);
    }

    /**
     * People whose workday status changes when this scope is opened or closed.
     *
     * @return list<int>
     */
    public function affectedUserIds(OpenedScope $scope, int $scopeId): array
    {
        if ($scope === OpenedScope::User) {
            return [$scopeId];
        }

        return DB::table('team_user')
            ->where('team_id', $scopeId)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * True when opening would change nothing: the date is already a workday for everyone in the scope.
     * A team without members is judged by the studio calendar alone.
     */
    public function isAlreadyWorkday(string $date, OpenedScope $scope, int $scopeId): bool
    {
        $userIds = $this->affectedUserIds($scope, $scopeId);

        if ($userIds === []) {
            return $this->isStudioWorkday($date);
        }

        $people = User::withTrashed()->whereIn('id', $userIds)->get();

        if ($people->isEmpty()) {
            return $this->isStudioWorkday($date);
        }

        return $people->every(fn (User $person) => $this->resolver->isWorkday($person, $date));
    }

    /** Workday by the studio calendar only (work week and calendar entries), before any opened dates. */
    public function isStudioWorkday(string $date): bool
    {
        $entry = CalendarDay::query()->whereDate('date', $date)->first();

        if ($entry) {
            return $entry->type->isWorkday();
        }

        $weekday = CarbonImmutable::parse($date)->dayOfWeekIso;

        return (bool) WorkWeekDay::query()->whereKey($weekday)->value('is_workday');
    }

    public function scopeName(OpenedScope $scope, int $scopeId): string
    {
        if ($scope === OpenedScope::Team) {
            return Team::query()->whereKey($scopeId)->value('name') ?? __('calendar::messages.deleted_team');
        }

        return User::withTrashed()->whereKey($scopeId)->value('name') ?? __('calendar::messages.deleted_person');
    }

    /** @return array<string, mixed> */
    public function snapshot(OpenedWorkday $opened): array
    {
        return [
            'date' => $opened->date->toDateString(),
            'scope_type' => $opened->scope_type->value,
            'scope_id' => $opened->scope_id,
            'opened_by' => $opened->opened_by,
            'note' => $opened->note,
        ];
    }

    private function resolveRights(User $user): OpeningRights
    {
        if ($user->hasPermission(Permission::ManageCalendar)) {
            return new OpeningRights(true);
        }

        if (! $user->hasPermission(Permission::OpenWorkdays)) {
            return OpeningRights::none();
        }

        if ($user->hasPermission(Permission::ApproveAnyOvertime)) {
            return new OpeningRights(true);
        }

        $teamIds = Team::query()->where('lead_user_id', $user->getKey())->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $userIds = $teamIds === [] ? [] : DB::table('team_user')
            ->whereIn('team_id', $teamIds)
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return new OpeningRights(false, $teamIds, $userIds);
    }
}
