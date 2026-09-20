<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Attendance\Support\Time;
use Carbon\CarbonImmutable;

/** A studio calendar month (Asia/Jakarta). Shifts belong to it by their work date. */
final readonly class ReportMonth
{
    private function __construct(public int $year, public int $month) {}

    /** Parses `YYYY-MM`; anything else is the current studio month. */
    public static function fromQuery(mixed $value, CarbonImmutable $now): self
    {
        if (is_string($value) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $m) && (int) $m[1] >= 2000 && (int) $m[1] <= 2999) {
            return new self((int) $m[1], (int) $m[2]);
        }

        return self::containing($now);
    }

    public static function containing(CarbonImmutable $moment): self
    {
        $local = $moment->setTimezone(Time::zone());

        return new self($local->year, $local->month);
    }

    public function value(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function firstDate(): string
    {
        return $this->start()->toDateString();
    }

    public function lastDate(): string
    {
        return $this->start()->endOfMonth()->toDateString();
    }

    /** The last moment of the month in the studio zone, as UTC. */
    public function endsAt(): CarbonImmutable
    {
        return $this->start()->endOfMonth()->utc();
    }

    public function previous(): self
    {
        $date = $this->start()->subMonthNoOverflow();

        return new self($date->year, $date->month);
    }

    public function next(): self
    {
        $date = $this->start()->addMonthNoOverflow();

        return new self($date->year, $date->month);
    }

    public function isCurrent(CarbonImmutable $now): bool
    {
        return $this->value() === self::containing($now)->value();
    }

    public function isFuture(CarbonImmutable $now): bool
    {
        return $this->value() > self::containing($now)->value();
    }

    private function start(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->month, 1, 0, 0, 0, Time::zone());
    }
}
