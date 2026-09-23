<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Leave\Http\Controllers\LeaveAdminController;
use App\Modules\Leave\Http\Controllers\LeaveApprovalsController;
use App\Modules\Leave\Http\Controllers\MyLeaveController;
use Illuminate\Support\Facades\Route;

// Cuti dan izin (docs/14 4). Who may decide or cancel a single request is checked by DecideLeave and CancelLeave.
$decides = 'permission:'.Permission::ApproveLeave->value.'|'.Permission::ManageLeave->value;

Route::middleware(['auth', 'password.changed'])->group(function () use ($decides) {
    Route::prefix('cuti')->name('leave.')->group(function () use ($decides) {
        Route::middleware('permission:'.Permission::RequestLeave->value)->group(function () {
            Route::get('/', [MyLeaveController::class, 'index'])->name('mine');
            Route::get('/hitung', [MyLeaveController::class, 'preview'])->middleware('throttle:60,1')->name('preview');
            Route::post('/', [MyLeaveController::class, 'store'])->middleware('throttle:20,1')->name('store');
        });

        Route::middleware($decides)->group(function () {
            Route::get('/persetujuan', [LeaveApprovalsController::class, 'index'])->name('approvals');
            Route::post('/{leave}/keputusan', [LeaveApprovalsController::class, 'decide'])->whereNumber('leave')->name('decide');
        });

        // The owner cancels their own; Superadmin cancels anyone's. The controller refuses everyone else.
        Route::post('/{leave}/batal', [MyLeaveController::class, 'cancel'])->whereNumber('leave')->name('cancel');
        Route::get('/{leave}/lampiran', [MyLeaveController::class, 'attachment'])->whereNumber('leave')->name('attachment');
    });

    Route::middleware('permission:'.Permission::ManageLeave->value)
        ->prefix('admin/cuti')
        ->name('admin.leave.')
        ->group(function () {
            Route::get('/', [LeaveAdminController::class, 'index'])->name('index');
            Route::put('/kuota/{user}', [LeaveAdminController::class, 'updateQuota'])->whereNumber('user')->name('quota');
            Route::post('/jenis', [LeaveAdminController::class, 'storeType'])->name('types.store');
            Route::put('/jenis/{leaveType}', [LeaveAdminController::class, 'updateType'])->whereNumber('leaveType')->name('types.update');
        });
});
