<?php

namespace App\Modules\Reporting\Exports;

use App\Modules\Reporting\Services\Recap;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Laporan as an .xlsx for payroll: "Ringkasan" (per person with team subtotals and a total) and "Harian" (one row
 * per shift). Built synchronously from the same Recap as the page (shared hosting runs no queue worker).
 */
class MonthlyRecapExport implements WithMultipleSheets
{
    public function __construct(public readonly Recap $recap) {}

    public function sheets(): array
    {
        return [
            new SummarySheet($this->recap),
            new DailySheet($this->recap),
        ];
    }

    /** Minutes as hours with two decimals, so payroll can multiply without converting. */
    public static function hours(int $minutes): float
    {
        return round($minutes / 60, 2);
    }

    public static function label(string $key, array $replace = []): string
    {
        return __('reporting::export.'.$key, $replace, 'id');
    }
}
