<?php

namespace App\Modules\Calendar\Policies;

use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Calendar\Services\WorkdayOpening;
use App\Modules\Identity\Models\User;

/**
 * Opening and closing extra workdays, limited to the teams and people the person may act for (see WorkdayOpening).
 */
class OpenedWorkdayPolicy
{
    public function __construct(private readonly WorkdayOpening $opening) {}

    public function create(User $user, OpenedScope $scope, int $scopeId): bool
    {
        return $this->opening->rightsFor($user)->permits($scope, $scopeId);
    }

    public function delete(User $user, OpenedWorkday $opened): bool
    {
        return $this->opening->rightsFor($user)->permits($opened->scope_type, (int) $opened->scope_id);
    }
}
