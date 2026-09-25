<?php

use App\Modules\Attendance\Http\Middleware\AcceptJson;
use App\Modules\Attendance\Http\Middleware\AuthenticateDevice;
use App\Modules\Identity\Access\Permission;
use App\Modules\Monitoring\Http\Controllers\Api\AppUsageUploadController;
use Illuminate\Support\Facades\Route;

// Aktivitas detail: the desktop app sends the application windows it saw during a shift (under /api/v1).
Route::middleware([AcceptJson::class, AuthenticateDevice::class, 'permission:'.Permission::ClockIn->value])
    ->post('app-usage', AppUsageUploadController::class)
    ->name('app-usage');
