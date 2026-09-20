<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Organization\Models\Team;
use App\Modules\Reporting\Exports\MonthlyRecapExport;
use App\Modules\Shared\Audit\AuditLog;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\Reports\Support\ReportMonthScenario;
use Tests\TestCase;

beforeEach(function () {
    $this->lead = userWithRole(Role::TeamLead);
    $this->person = userWithRole(Role::Employee);
    $this->manager = userWithRole(Role::ProjectManager);
    $this->admin = userWithRole(Role::Superadmin);

    $this->team = Team::factory()->create(['name' => 'Animation', 'lead_user_id' => $this->lead->id]);
    $this->team->members()->attach([$this->person->id, $this->lead->id]);
    $this->person->forceFill(['employee_code' => 'OWL-017'])->save();
});

/** Downloads the real file and opens it. */
function reportDownload(TestCase $test, $viewer, array $query, string $fileName): Spreadsheet
{
    $response = $test->actingAs($viewer)->get(route('reports.export', $query))->assertOk()->assertDownload($fileName);
    $path = $response->baseResponse->getFile()->getPathname();

    try {
        return IOFactory::load($path);
    } finally {
        @unlink($path);
    }
}

/** @return list<list<mixed>> formatted cell values, as a person reading the sheet sees them */
function reportSheetRows(Spreadsheet $book, string $title): array
{
    return $book->getSheetByName($title)->toArray(null, true, true, false);
}

it('lets only people with the export permission download', function () {
    $this->travelTo(Desk::time('2026-10-05 10:00'));

    $this->actingAs($this->lead)->get(route('reports.export', ['bulan' => '2026-09']))->assertForbidden();
    $this->actingAs($this->manager)->get(route('reports.export', ['bulan' => '2026-09']))->assertForbidden();
    $this->actingAs($this->person)->get(route('reports.export', ['bulan' => '2026-09']))->assertForbidden();

    Excel::fake();

    $this->actingAs($this->admin)->get(route('reports.export', ['bulan' => '2026-09']))->assertOk();

    Excel::assertDownloaded('owlorix-hr-laporan-2026-09.xlsx', fn (MonthlyRecapExport $export) => $export->recap->month->value() === '2026-09');
});

it('names a team export after the team and records the export in the audit log', function () {
    $this->travelTo(Desk::time('2026-10-05 10:00'));
    Excel::fake();

    $this->actingAs($this->admin)->get(route('reports.export', ['bulan' => '2026-09', 'tim' => $this->team->id]))->assertOk();

    Excel::assertDownloaded('owlorix-hr-laporan-2026-09-animation.xlsx', fn (MonthlyRecapExport $export) => $export->recap->team?->is($this->team) === true);

    $audit = AuditLog::query()->where('action', 'reports.exported')->sole();

    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->subject_id)->toBe($this->team->id)
        ->and($audit->after)->toMatchArray(['month' => '2026-09', 'team_id' => $this->team->id, 'people' => 2, 'shifts' => 0]);
});

it('writes Ringkasan with team subtotals and the studio total, and Harian with one row per shift', function () {
    ReportMonthScenario::play($this, $this->person, $this->lead);
    $this->travelTo(Desk::time('2026-10-05 10:00'));

    $book = reportDownload($this, $this->admin, ['bulan' => '2026-09'], 'owlorix-hr-laporan-2026-09.xlsx');

    expect($book->getSheetNames())->toBe(['Ringkasan', 'Harian']);

    $summary = reportSheetRows($book, 'Ringkasan');
    $t = ReportMonthScenario::TOTALS;

    expect($summary[0][0])->toBe('Tim')
        ->and($summary[0][5])->toBe('Jam reguler (menit)');

    $personRow = collect($summary)->first(fn ($row) => $row[2] === $this->person->username);
    $raw = $book->getSheetByName('Ringkasan')->rangeToArray('A1:S40', null, false, false, false);
    $rawPerson = collect($raw)->first(fn ($row) => $row[2] === $this->person->username);

    expect($personRow[0])->toBe('Animation')
        ->and($personRow[3])->toBe('OWL-017')
        ->and($rawPerson[4])->toBe($t['days_worked'])
        ->and($rawPerson[5])->toBe($t['regular_minutes'])
        ->and($rawPerson[6])->toBe(59.5)
        ->and($personRow[6])->toBe('59.50')
        ->and($rawPerson[7])->toBe(120)
        ->and($rawPerson[9])->toBe(240)
        ->and($rawPerson[11])->toBe(30)
        ->and($rawPerson[13])->toBe(30)
        ->and(array_slice($rawPerson, 15, 4))->toBe([2, 1, 0, 0]);

    $labels = collect($summary)->pluck(1)->all();

    expect($labels)->toContain('Subtotal Animation')
        ->and($labels)->toContain('Subtotal Tanpa tim')
        ->and($labels)->toContain('Total studio')
        ->and(collect($summary)->pluck(0)->filter(fn ($v) => is_string($v) && str_contains($v, 'PC diam hanya konteks'))->count())->toBe(1);

    $daily = reportSheetRows($book, 'Harian');
    $shifts = collect($daily)->slice(1)->values();

    expect($daily[0])->toBe([
        'Nama', 'Username', 'Kode karyawan', 'Tim', 'Tanggal kerja', 'Jenis hari', 'Absen masuk (WIB)', 'Absen pulang (WIB)',
        'Reguler (menit)', 'Reguler (jam)', 'Lembur (menit)', 'Lembur (jam)', 'Status lembur', 'PC diam (menit)', 'Terputus (menit)', 'Catatan',
    ])
        ->and($shifts)->toHaveCount(ReportMonthScenario::SHIFTS);

    $approved = $shifts->first(fn ($row) => $row[4] === '2026-09-16');
    $saturday = $shifts->first(fn ($row) => $row[4] === '2026-09-19');
    $midnight = $shifts->first(fn ($row) => $row[4] === '2026-09-23');

    expect(array_slice($approved, 0, 4))->toBe([$this->person->name, $this->person->username, 'OWL-017', 'Animation'])
        ->and(array_slice($approved, 5, 8))->toBe(['Hari kerja', '2026-09-16 09:00', '2026-09-16 19:00', '480', '8.00', '120', '2.00', 'Disetujui'])
        ->and($saturday[5])->toBe('Bukan hari kerja')
        ->and($saturday[12])->toBe('Menunggu')
        ->and([$midnight[6], $midnight[7], $midnight[8], $midnight[15]])->toBe(['2026-09-23 20:00', '2026-09-24 01:30', '330', 'Hari pendek']);
});

it('puts the same totals in the file as on the page', function () {
    ReportMonthScenario::play($this, $this->person, $this->lead);
    $this->travelTo(Desk::time('2026-10-05 10:00'));

    $report = $this->actingAs($this->admin)->get(route('reports.index', ['bulan' => '2026-09']))->assertOk()->viewData('page')['props']['report'];
    $book = reportDownload($this, $this->admin, ['bulan' => '2026-09'], 'owlorix-hr-laporan-2026-09.xlsx');

    $raw = collect($book->getSheetByName('Ringkasan')->rangeToArray('A1:S40', null, false, false, false));
    $total = $raw->first(fn ($row) => $row[1] === 'Total studio');
    $animation = $raw->first(fn ($row) => $row[1] === 'Subtotal Animation');
    $pageAnimation = collect($report['groups'])->firstWhere('team.name', 'Animation')['subtotal'];

    $columns = fn (array $row) => [
        'days_worked' => $row[4],
        'regular_minutes' => $row[5],
        'overtime_approved_minutes' => $row[7],
        'overtime_pending_minutes' => $row[9],
        'overtime_rejected_minutes' => $row[11],
        'idle_minutes' => $row[13],
        'short_days' => $row[15],
        'non_workday_shifts' => $row[16],
        'review_shifts' => $row[17],
        'late_claims' => $row[18],
    ];
    $page = fn (array $totals) => collect($totals)->only(array_keys($columns($total)))->all();

    expect($columns($total))->toEqual($page($report['total']))
        ->and($columns($animation))->toEqual($page($pageAnimation))
        ->and($report['total']['regular_minutes'])->toBe(ReportMonthScenario::TOTALS['regular_minutes']);
});
