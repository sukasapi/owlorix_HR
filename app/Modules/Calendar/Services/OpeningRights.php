<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\Enums\OpenedScope;

/**
 * Which teams and people one person may open or close workdays for (proposal for Q17).
 */
final readonly class OpeningRights
{
    /**
     * @param  bool  $any  true for Superadmin, Project Manager, Project Director
     * @param  list<int>  $teamIds  teams the person leads
     * @param  list<int>  $userIds  members of those teams
     */
    public function __construct(
        public bool $any,
        public array $teamIds = [],
        public array $userIds = [],
    ) {}

    public static function none(): self
    {
        return new self(false);
    }

    public function permits(OpenedScope $scope, int $scopeId): bool
    {
        if ($this->any) {
            return true;
        }

        return in_array($scopeId, $scope === OpenedScope::Team ? $this->teamIds : $this->userIds, true);
    }

    public function isEmpty(): bool
    {
        return ! $this->any && $this->teamIds === [] && $this->userIds === [];
    }
}
