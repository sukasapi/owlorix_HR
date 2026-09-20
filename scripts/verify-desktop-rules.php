<?php

/**
 * Shorten attendance rules on owlorix_hr_verify for desktop click-through, then restore.
 * Usage:
 *   php scripts/verify-desktop-rules.php short
 *   php scripts/verify-desktop-rules.php restore
 */
use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Settings\Settings;
use Illuminate\Support\Facades\DB;

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

$mode = $argv[1] ?? '';
$settings = app(Settings::class);

$short = [
    'attendance.regular_limit_minutes' => 1,
    'attendance.idle_threshold_minutes' => 1,
    'attendance.prompt_repeat_minutes' => 1,
    'attendance.prompt_auto_close_minutes' => 5,
    // Direct Settings::set bypasses Aturan form floor (15); desktop config accepts ≥1.
    'attendance.overtime_idle_check_minutes' => 1,
    'attendance.overtime_idle_answer_minutes' => 5,
];

$defaults = [
    'attendance.regular_limit_minutes' => 480,
    'attendance.idle_threshold_minutes' => 10,
    'attendance.prompt_repeat_minutes' => 10,
    'attendance.prompt_auto_close_minutes' => 30,
    'attendance.overtime_idle_check_minutes' => 60,
    'attendance.overtime_idle_answer_minutes' => 30,
];

if ($mode === 'short') {
    foreach ($short as $key => $value) {
        $settings->set($key, $value);
    }
    // Close any leftover open shifts for the animator we will use
    $anim = User::where('username', 'anim.uji.a')->first();
    if ($anim) {
        $open = Shift::query()->where('user_id', $anim->id)->whereNull('clock_out_at')->get();
        foreach ($open as $shift) {
            $shift->update([
                'clock_out_at' => now(),
                'status' => 'closed',
            ]);
        }
        echo 'closed_open_shifts='.$open->count().PHP_EOL;
    }
    echo json_encode($short, JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}

if ($mode === 'restore') {
    foreach ($defaults as $key => $value) {
        DB::table('settings')->where('key', $key)->delete();
    }
    echo "restored to config defaults\n";
    exit(0);
}

fwrite(STDERR, "usage: php scripts/verify-desktop-rules.php short|restore\n");
exit(1);
