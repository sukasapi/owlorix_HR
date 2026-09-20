<?php

namespace App\Modules\Organization\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when people join or leave a team, or the team is deleted. Calendar listens because
 * workdays opened for a team apply to its current members.
 */
final class TeamMembersChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $userIds  people who joined or left (all members when the team is deleted)
     */
    public function __construct(
        public readonly int $teamId,
        public readonly array $userIds,
        public readonly bool $teamDeleted = false,
    ) {}
}
