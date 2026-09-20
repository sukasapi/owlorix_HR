<?php

namespace App\Modules\Attendance\Services;

use Carbon\CarbonImmutable;
use RuntimeException;

/** A late claim the rules do not allow; the code is stable for clients, the message is for people. */
final class LateClaimRefused extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
        public readonly ?CarbonImmutable $latestEndAt = null,
    ) {
        parent::__construct($message);
    }
}
