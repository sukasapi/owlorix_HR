<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Monitoring\Http\Controllers\ActivityMonitorController;
use Illuminate\Support\Facades\Route;

// Monitor aktivitas: sign-ins, access, refusals, and one timeline with audit and attendance (Superadmin, docs/14 2).
Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ViewActivityMonitor->value])
    ->get('/admin/monitor-aktivitas', ActivityMonitorController::class)
    ->name('admin.activity.index');
