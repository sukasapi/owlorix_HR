<?php

use App\Modules\Attendance\Http\Controllers\HistoryController;
use App\Modules\Attendance\Http\Controllers\MyDayController;
use App\Modules\Identity\Access\Permission;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'password.changed'])->group(function () {
    Route::get('/', MyDayController::class)->name('my-day');
});

Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ClockIn->value])->group(function () {
    Route::get('/riwayat', HistoryController::class)->name('history');
});
