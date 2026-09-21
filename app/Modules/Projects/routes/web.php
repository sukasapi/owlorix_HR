<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Projects\Http\Controllers\ActivityLogController;
use App\Modules\Projects\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'password.changed'])->group(function () {
    Route::middleware('permission:'.Permission::ViewProjects->value.'|'.Permission::ManageProjects->value)
        ->prefix('proyek')
        ->name('projects.')
        ->group(function () {
            Route::get('/', [ProjectController::class, 'index'])->name('index');
            Route::get('/saya', [ProjectController::class, 'mine'])->name('mine');
            Route::get('/{project}', [ProjectController::class, 'show'])->name('show');

            Route::middleware('permission:'.Permission::ManageProjects->value)->group(function () {
                Route::post('/', [ProjectController::class, 'store'])->name('store');
                Route::put('/{project}', [ProjectController::class, 'update'])->name('update');
                Route::post('/{project}/anggota', [ProjectController::class, 'assign'])->name('assign');
                Route::delete('/{project}/anggota/{user}', [ProjectController::class, 'unassign'])->name('unassign');
            });
        });

    Route::middleware('permission:'.Permission::LogActivity->value)
        ->prefix('log-kerja')
        ->name('activity.')
        ->group(function () {
            Route::get('/', [ActivityLogController::class, 'index'])->name('index');
            Route::post('/', [ActivityLogController::class, 'store'])->name('store');
            Route::put('/{activity}', [ActivityLogController::class, 'update'])->name('update');
            Route::delete('/{activity}', [ActivityLogController::class, 'destroy'])->name('destroy');
        });
});
