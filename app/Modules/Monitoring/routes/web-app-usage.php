<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Monitoring\Http\Controllers\AppUsageController;
use Illuminate\Support\Facades\Route;

// Aktivitas detail: applications and window titles during work, per person and day (Superadmin only, 2026-09-25).
Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ViewAppUsage->value])
    ->get('/aktivitas-detail', AppUsageController::class)
    ->name('monitoring.app-usage');
