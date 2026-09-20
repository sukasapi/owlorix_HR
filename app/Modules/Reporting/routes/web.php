<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Reporting\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

$viewReports = 'permission:'.Permission::ViewTeamReports->value.'|'.Permission::ViewAllReports->value;

// Whose rows a viewer reads (all people or the teams they lead) is decided by ReportScope.
Route::middleware(['auth', 'password.changed', $viewReports])->prefix('laporan')->name('reports.')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::get('/ekspor', [ReportController::class, 'export'])
        ->middleware('permission:'.Permission::ExportReports->value)
        ->name('export');
});
