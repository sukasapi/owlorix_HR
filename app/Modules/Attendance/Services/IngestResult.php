<?php

namespace App\Modules\Attendance\Services;

final readonly class IngestResult
{
    /**
     * @param  list<string>  $accepted  stored now, or heartbeats applied
     * @param  list<string>  $duplicates  already stored earlier for this person; nothing changed
     * @param  list<array{id: string|null, index: int, code: string, errors?: array<string, list<string>>}>  $rejected  never stored; the PC should not resend them
     */
    public function __construct(
        public array $accepted,
        public array $duplicates,
        public array $rejected = [],
    ) {}
}
