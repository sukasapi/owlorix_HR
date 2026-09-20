<?php

use App\Modules\Attendance\Http\Controllers\Api\ConfigController;
use App\Modules\Attendance\Http\Controllers\Api\CurrentShiftController;
use App\Modules\Attendance\Http\Controllers\Api\OvertimeClaimController;
use App\Modules\Attendance\Http\Controllers\Api\SyncEventsController;
use App\Modules\Attendance\Http\Middleware\AcceptJson;
use App\Modules\Attendance\Http\Middleware\AuthenticateDevice;
use App\Modules\Identity\Access\Permission;
use Illuminate\Support\Facades\Route;

// Desktop API (docs/03-architecture.md 4.2), served under /api/v1.
Route::middleware([AcceptJson::class, AuthenticateDevice::class])->group(function () {
    Route::get('me/shift', CurrentShiftController::class)->name('me.shift');
    Route::get('config', ConfigController::class)->name('config');

    Route::middleware('permission:'.Permission::ClockIn->value)->group(function () {
        Route::post('sync/events', SyncEventsController::class)->name('sync.events');
        Route::post('overtime/{shift}/claim', OvertimeClaimController::class)->whereNumber('shift')->name('overtime.claim');
    });
});
