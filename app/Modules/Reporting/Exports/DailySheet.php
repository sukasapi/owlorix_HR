<?php

namespace App\Modules\Reporting\Exports;

use App\Modules\Attendance\Support\Time;
use App\Modules\Reporting\Services\Recap;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** "Harian": one row per shift, people by name, shifts in clock-in order. Dates and times are real Excel values in WIB. */
class DailySheet implements FromArray, WithColumnFormatting, WithColumnWidths, WithEvents, WithStrictNullComparison, WithStyles, WithTitle
{
    public function __construct(private readonly Recap $recap) {}

    public function title(): string
    {
        return MonthlyRecapExport::label('sheets.daily');
    }

    public function array(): array
    {
        $l = fn (string $key) => MonthlyRecapExport::label('daily.'.$key);

        $rows = [[
            $l('name'), $l('username'), $l('employee_code'), $l('team'), $l('work_date'), $l('day_type'),
            $l('clock_in'), $l('clock_out'),
            $l('regular_minutes'), $l('regular_hours'),
            $l('overtime_minutes'), $l('overtime_hours'), $l('overtime_status'),
            $l('idle_minutes'), $l('interruption_minutes'), $l('notes'),
        ]];

        foreach ($this->recap->people as $person) {
            foreach ($person->lines as $line) {
                $rows[] = [
                    $person->user->name,
                    $person->user->username,
                    $person->user->employee_code,
                    implode(', ', $person->teamNames),
                    ExcelDate::dateTimeToExcel(CarbonImmutable::parse($line->workDate, Time::zone())),
                    $line->isWorkday ? $l('workday') : $l('non_workday'),
                    $this->excelTime($line->clockInAt),
                    $this->excelTime($line->clockOutAt),
                    $line->regularMinutes,
                    MonthlyRecapExport::hours($line->regularMinutes),
                    $line->overtimeMinutes,
                    MonthlyRecapExport::hours($line->overtimeMinutes),
                    $line->overtimeStatus !== null ? MonthlyRecapExport::label('overtime_status.'.$line->overtimeStatus->value) : null,
                    $line->idleMinutes,
                    $line->interruptionMinutes,
                    implode(', ', array_map(fn (string $note) => MonthlyRecapExport::label('notes.'.$note), $line->notes())),
                ];
            }
        }

        return $rows;
    }

    /** @return float|null an Excel serial for the Asia/Jakarta wall clock */
    private function excelTime(?CarbonImmutable $moment): ?float
    {
        return $moment === null ? null : (float) ExcelDate::dateTimeToExcel($moment->setTimezone(Time::zone()));
    }

    public function columnFormats(): array
    {
        return [
            'E' => 'yyyy-mm-dd',
            'G' => 'yyyy-mm-dd hh:mm',
            'H' => 'yyyy-mm-dd hh:mm',
            'J' => '0.00',
            'L' => '0.00',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 26, 'B' => 16, 'C' => 14, 'D' => 20, 'E' => 12, 'F' => 16, 'G' => 17, 'H' => 17,
            'I' => 11, 'J' => 11, 'K' => 11, 'L' => 11, 'M' => 13, 'N' => 11, 'O' => 11, 'P' => 36,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true], 'alignment' => ['wrapText' => true, 'vertical' => 'top']]];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $event->sheet->getDelegate()->freezePane('B2'),
        ];
    }
}
