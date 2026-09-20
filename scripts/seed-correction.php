<?php

/** Seed one waiting correction on owlorix_hr_verify for click-through (not for owlorix_hrdb). */
use App\Modules\Attendance\Enums\CorrectionField;
use App\Modules\Attendance\Models\Correction;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\CorrectionWorkflow;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Without DB_DATABASE in the shell, web/.env points at owlorix_hrdb (real data).
if (Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'owlorix_hr_verify') {
    fwrite(STDERR, "Refused: run with DB_DATABASE=owlorix_hr_verify.\n");
    exit(1);
}

$lead = User::where('username', 'lead.uji')->firstOrFail();
$anim = User::where('username', 'anim.uji.a')->firstOrFail();

Correction::query()->where('status', 'proposed')->delete();

$shift = Shift::query()
    ->where('user_id', $anim->id)
    ->whereNotNull('clock_out_at')
    ->orderByDesc('id')
    ->firstOrFail();

$in = CarbonImmutable::parse($shift->clock_in_at)->setTimezone(Time::zone());
$newOut = $in->addHours(2);

$workflow = app(CorrectionWorkflow::class);
$correction = $workflow->propose($lead, $shift, CorrectionField::ClockOut, $newOut, 'Lupa absen pulang, sudah dicek dengan lead');

echo json_encode([
    'correction_id' => $correction->id,
    'shift_id' => $shift->id,
    'person' => $anim->name,
    'old' => (string) $shift->clock_out_at,
    'new' => $newOut->utc()->format('Y-m-d\TH:i:s.v\Z'),
], JSON_PRETTY_PRINT).PHP_EOL;
