<?php

namespace App\Modules\Reporting\Exports;

use App\Modules\Attendance\Support\Time;
use App\Modules\Reporting\Services\PersonRow;
use App\Modules\Reporting\Services\Recap;
use App\Modules\Reporting\Services\Totals;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** "Ringkasan": the per-person table of the page, with a subtotal row after each team and the total last. */
class SummarySheet implements FromArray, WithColumnFormatting, WithColumnWidths, WithEvents, WithStrictNullComparison, WithStyles, WithTitle
{
    /** @var list<int> spreadsheet rows (1-based) written bold */
    private array $boldRows = [1];

    public function __construct(private readonly Recap $recap) {}

    public function title(): string
    {
        return MonthlyRecapExport::label('sheets.summary');
    }

    public function array(): array
    {
        $l = fn (string $key, array $replace = []) => MonthlyRecapExport::label('summary.'.$key, $replace);

        $rows = [[
            $l('team'), $l('name'), $l('username'), $l('employee_code'), $l('days_worked'),
            $l('regular_minutes'), $l('regular_hours'),
            $l('approved_minutes'), $l('approved_hours'),
            $l('pending_minutes'), $l('pending_hours'),
            $l('rejected_minutes'), $l('rejected_hours'),
            $l('idle_minutes'), $l('idle_hours'),
            $l('short_days'), $l('non_workday_shifts'), $l('review_shifts'), $l('late_claims'),
        ]];

        foreach ($this->recap->groups as $group) {
            $teamName = $group['team']['name'] ?? $l('no_team');

            foreach ($group['people'] as $person) {
                $rows[] = $this->personRow($teamName, $person);
            }

            $rows[] = $this->totalsRow($teamName, $l('subtotal', ['team' => $teamName]), $group['subtotal']);
            $this->boldRows[] = count($rows);
        }

        if ($this->recap->groups !== []) {
            $label = $this->recap->isStudio() ? $l('total_studio') : $l('total_selection');
            $rows[] = $this->totalsRow('', $label, $this->recap->total);
            $this->boldRows[] = count($rows);
        }

        $f = fn (string $key, array $replace = []) => MonthlyRecapExport::label('footer.'.$key, $replace);
        $generated = $this->recap->generatedAt->setTimezone(Time::zone());

        $rows[] = [];
        $rows[] = [$f('period', ['month' => $this->recap->month->value()])];
        $rows[] = [$f('generated', ['time' => $generated->format('Y-m-d H:i')])];

        if ($this->recap->month->isCurrent($this->recap->generatedAt)) {
            $rows[] = [$f('running_month')];
        }

        $rows[] = [$f('idle')];
        $rows[] = [$f('rejected')];

        if ($this->recap->hasSharedPeople()) {
            $rows[] = [$f('shared')];
        }

        return $rows;
    }

    /** @return list<string|int|float|null> */
    private function personRow(string $teamName, PersonRow $person): array
    {
        return [
            $teamName,
            $person->user->name,
            $person->user->username,
            $person->user->employee_code,
            ...$this->numbers($person->totals),
        ];
    }

    /** @return list<string|int|float|null> */
    private function totalsRow(string $teamName, string $label, Totals $totals): array
    {
        return [
            $teamName,
            $label,
            MonthlyRecapExport::label('summary.people_count', ['count' => $totals->people]),
            null,
            ...$this->numbers($totals),
        ];
    }

    /** @return list<int|float> */
    private function numbers(Totals $t): array
    {
        return [
            $t->daysWorked,
            $t->regularMinutes, MonthlyRecapExport::hours($t->regularMinutes),
            $t->overtimeApprovedMinutes, MonthlyRecapExport::hours($t->overtimeApprovedMinutes),
            $t->overtimePendingMinutes, MonthlyRecapExport::hours($t->overtimePendingMinutes),
            $t->overtimeRejectedMinutes, MonthlyRecapExport::hours($t->overtimeRejectedMinutes),
            $t->idleMinutes, MonthlyRecapExport::hours($t->idleMinutes),
            $t->shortDays,
            $t->nonWorkdayShifts,
            $t->reviewShifts,
            $t->lateClaims,
        ];
    }

    public function columnFormats(): array
    {
        return array_fill_keys(['G', 'I', 'K', 'M', 'O'], NumberFormat::FORMAT_NUMBER_00);
    }

    /** Fixed widths: the notes below the table would stretch an auto-sized first column. */
    public function columnWidths(): array
    {
        return ['A' => 18, 'B' => 28, 'C' => 16, 'D' => 14] + array_fill_keys(range('E', 'S'), 13);
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true], 'alignment' => ['wrapText' => true, 'vertical' => 'top']]]
            + array_fill_keys($this->boldRows, ['font' => ['bold' => true]]);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $event->sheet->getDelegate()->freezePane('C2'),
        ];
    }
}
