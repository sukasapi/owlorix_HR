<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Http\Controllers\Admin\DeviceController;
use Illuminate\Support\Facades\Route;

// Perangkat: studio PCs and browsers, revoke and restore (Superadmin).
Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ManageDevices->value])
    ->prefix('admin/perangkat')
    ->name('admin.devices.')
    ->group(function () {
        Route::get('/', [DeviceController::class, 'index'])->name('index');
        Route::post('/{device}/cabut', [DeviceController::class, 'revoke'])->name('revoke')->where('device', '[A-Za-z0-9._:\-]+');
        Route::post('/{device}/pulihkan', [DeviceController::class, 'restore'])->name('restore')->where('device', '[A-Za-z0-9._:\-]+');
    });
