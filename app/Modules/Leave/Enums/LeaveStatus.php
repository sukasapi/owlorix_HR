<?php

namespace App\Modules\Leave\Enums;

enum LeaveStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Pending and approved requests hold their dates and, for quota types, their days. */
    public function holdsDays(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }

    /** @return list<string> */
    public static function holding(): array
    {
        return [self::Pending->value, self::Approved->value];
    }
}
