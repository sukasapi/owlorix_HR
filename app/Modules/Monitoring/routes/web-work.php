<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Monitoring\Http\Controllers\WorkloadController;
use App\Modules\Monitoring\Http\Controllers\WorkMonitorController;
use Illuminate\Support\Facades\Route;

// Monitor kerja and Beban kerja (docs/14 5): Team Lead for their teams and led sub projects, PM, PD, Superadmin for the studio.
Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ViewWorkMonitor->value])->group(function () {
    Route::get('/monitor-kerja', WorkMonitorController::class)->name('monitoring.work');
    Route::get('/beban-kerja', WorkloadController::class)->name('monitoring.workload');
});
