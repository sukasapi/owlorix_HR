<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Access\Permission;
use App\Modules\Reporting\Exports\MonthlyRecapExport;
use App\Modules\Reporting\Services\MonthlyRecap;
use App\Modules\Reporting\Services\PersonRow;
use App\Modules\Reporting\Services\Recap;
use App\Modules\Reporting\Services\ReportMonth;
use App\Modules\Shared\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Laporan: the monthly recap per person and per team (PRD 4.2.7), a per-day breakdown for one person, and the
 * Excel export. `?bulan=YYYY-MM&tim=ID&orang=ID`.
 */
class ReportController extends Controller
{
    public function index(Request $request, MonthlyRecap $recap): Response
    {
        $viewer = $request->user();
        $now = CarbonImmutable::now();
        $month = ReportMonth::fromQuery($request->query('bulan'), $now);
        $personId = $this->id($request->query('orang'));

        $report = $recap->build($viewer, $month, $this->id($request->query('tim')), $now);
        $person = null;

        if ($personId !== null) {
            $person = $recap->person($viewer, $month, $personId, $now);
            abort_if($person === null, 404);
        }

        return Inertia::render('reports/Index', [
            'month' => [
                'value' => $month->value(),
                'previous' => $month->previous()->value(),
                'next' => $month->isCurrent($now) || $month->isFuture($now) ? null : $month->next()->value(),
                'current' => ReportMonth::containing($now)->value(),
                'is_current' => $month->isCurrent($now),
                'is_future' => $month->isFuture($now),
            ],
            'filters' => [
                'team' => $report->team?->id,
                'person' => $person?->user->id,
            ],
            'teams' => $report->teams->map(fn ($team) => ['id' => $team->id, 'name' => $team->name])->values()->all(),
            'scope' => $report->seesEveryone ? 'everyone' : 'led_teams',
            'report' => $person === null ? $this->summary($report) : null,
            'detail' => $person === null ? null : [
                'person' => $person->toArray(),
                'shifts' => array_map(fn ($line) => $line->toArray(), $person->lines),
            ],
            'generated_at' => Time::iso($now),
            'can' => [
                'export' => $viewer->hasPermission(Permission::ExportReports),
            ],
            'links' => [
                'approvals' => Route::has('approvals.index') && $viewer->hasPermission(Permission::ApproveOvertime)
                    ? route('approvals.index', absolute: false)
                    : null,
            ],
        ]);
    }

    public function export(Request $request, MonthlyRecap $recap, Auditor $auditor): BinaryFileResponse
    {
        $now = CarbonImmutable::now();
        $month = ReportMonth::fromQuery($request->query('bulan'), $now);
        $report = $recap->build($request->user(), $month, $this->id($request->query('tim')), $now);

        $fileName = 'owlorix-hr-laporan-'.$month->value().($report->team !== null ? '-'.Str::slug($report->team->name) : '').'.xlsx';

        $auditor->record('reports.exported', $report->team, null, [
            'month' => $month->value(),
            'team_id' => $report->team?->id,
            'people' => count($report->people),
            'shifts' => $report->shiftCount(),
            'file' => $fileName,
        ]);

        return Excel::download(new MonthlyRecapExport($report), $fileName);
    }

    /** @return array<string, mixed> */
    private function summary(Recap $report): array
    {
        return [
            'groups' => array_map(fn (array $group) => [
                'team' => $group['team'],
                'people' => array_map(fn (PersonRow $row) => $row->toArray(), $group['people']),
                'subtotal' => $group['subtotal']->toArray(),
            ], $report->groups),
            'total' => $report->total->toArray(),
            'is_studio' => $report->isStudio(),
            'shared_people' => $report->hasSharedPeople(),
        ];
    }

    private function id(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) && strlen($value) <= 18 ? (int) $value : null;
    }
}
