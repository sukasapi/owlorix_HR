<?php

use App\Modules\Attendance\Http\Controllers\WebClockController;
use App\Modules\Attendance\Http\Controllers\WebClockOvertimeController;
use App\Modules\Attendance\Http\Middleware\EnsureWebClockIn;
use App\Modules\Identity\Access\Permission;
use Illuminate\Support\Facades\Route;

// Web clock-in (docs/02-attendance-rules.md 3.11). Session and CSRF from the web group. 60 requests a minute per
// person: one open tab sends a heartbeat a minute, so this leaves room for a few tabs and every action.
Route::middleware(['auth', 'password.changed', 'throttle:60,1', 'permission:'.Permission::ClockIn->value, EnsureWebClockIn::class])
    ->prefix('absen')
    ->name('web-clock.')
    ->group(function () {
        Route::post('masuk', [WebClockController::class, 'clockIn'])->name('clock-in');
        Route::post('batal', [WebClockController::class, 'cancel'])->name('cancel');
        Route::post('pindah', [WebClockController::class, 'move'])->name('move');
        Route::post('lanjut', [WebClockController::class, 'resume'])->name('resume');
        Route::post('pulang', [WebClockController::class, 'clockOut'])->name('clock-out');
        Route::post('jawab-pulang', [WebClockController::class, 'clockOut'])->name('prompt-clock-out');
        Route::post('detak', [WebClockController::class, 'heartbeat'])->name('heartbeat');
        Route::post('lembur', [WebClockOvertimeController::class, 'keepWorking'])->name('keep-working');
        Route::post('masih-lembur', [WebClockOvertimeController::class, 'confirmPresence'])->name('still-working');
        Route::post('laporan', [WebClockOvertimeController::class, 'report'])->name('report');
        Route::post('klaim', [WebClockOvertimeController::class, 'claim'])->name('claim');
    });
