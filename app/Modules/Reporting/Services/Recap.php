<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Organization\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The month report for one viewer. A person in several teams appears under each of those teams and their subtotals;
 * `people` and `total` count everyone once.
 */
final readonly class Recap
{
    /**
     * @param  Collection<int, Team>  $teams  teams the viewer can filter by
     * @param  list<array{team: array{id: int, name: string}|null, people: list<PersonRow>, subtotal: Totals}>  $groups
     * @param  list<PersonRow>  $people  every person in the groups once, by name
     */
    public function __construct(
        public ReportMonth $month,
        public bool $seesEveryone,
        public ?Team $team,
        public Collection $teams,
        public array $groups,
        public array $people,
        public Totals $total,
        public CarbonImmutable $generatedAt,
    ) {}

    /** The whole studio: everyone in scope, no team filter. */
    public function isStudio(): bool
    {
        return $this->seesEveryone && $this->team === null;
    }

    public function shiftCount(): int
    {
        return array_sum(array_map(fn (PersonRow $row) => count($row->lines), $this->people));
    }

    public function hasSharedPeople(): bool
    {
        $appearances = array_sum(array_map(fn (array $group) => count($group['people']), $this->groups));

        return $appearances > count($this->people);
    }
}
