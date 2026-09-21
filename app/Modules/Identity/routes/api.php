<?php

use App\Modules\Attendance\Http\Middleware\AcceptJson;
use App\Modules\Attendance\Http\Middleware\AuthenticateDevice;
use App\Modules\Identity\Http\Controllers\Api\DeviceLoginController;
use App\Modules\Identity\Http\Controllers\Api\DeviceLogoutController;
use App\Modules\Identity\Http\Controllers\Api\DevicePasswordController;
use App\Modules\Identity\Http\Controllers\Api\DeviceWebHandoffController;
use Illuminate\Support\Facades\Route;

// Desktop sign-in, served under /api/v1.
Route::middleware(AcceptJson::class)->prefix('auth')->name('auth.')->group(function () {
    Route::post('device-login', DeviceLoginController::class)->name('device-login');

    Route::middleware(AuthenticateDevice::class)->group(function () {
        Route::post('logout', DeviceLogoutController::class)->name('logout');
        Route::post('password', DevicePasswordController::class)->name('password');
        Route::post('web-handoff', DeviceWebHandoffController::class)->name('web-handoff');
    });
});
