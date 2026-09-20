<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Overtime\Http\Controllers\MyOvertimeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ClockIn->value])->group(function () {
    Route::get('/lembur', MyOvertimeController::class)->name('overtime.mine');
});
