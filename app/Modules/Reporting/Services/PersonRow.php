<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Identity\Models\User;

/** One person's month: their shifts and the numbers added up from them. */
final readonly class PersonRow
{
    /**
     * @param  list<string>  $teamNames  teams inside the viewer's scope, by name
     * @param  list<ShiftLine>  $lines  in clock-in order
     */
    public function __construct(
        public User $user,
        public array $teamNames,
        public Totals $totals,
        public array $lines,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->user->id,
            'name' => $this->user->name,
            'username' => $this->user->username,
            'employee_code' => $this->user->employee_code,
            'initials' => $this->user->initials(),
            'status' => $this->user->status->value,
            'teams' => $this->teamNames,
            ...$this->totals->toArray(),
        ];
    }
}
