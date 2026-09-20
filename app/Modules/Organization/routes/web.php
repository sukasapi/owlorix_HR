<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Organization\Http\Controllers\TeamController;
use App\Modules\Organization\Http\Controllers\TeamMemberController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ManageTeams->value])
    ->prefix('admin/tim')
    ->name('admin.teams.')
    ->group(function () {
        Route::get('/', [TeamController::class, 'index'])->name('index');
        Route::post('/', [TeamController::class, 'store'])->name('store');
        Route::put('/{team}', [TeamController::class, 'update'])->name('update');
        Route::delete('/{team}', [TeamController::class, 'destroy'])->name('destroy');
        Route::post('/{team}/anggota', [TeamMemberController::class, 'store'])->name('members.store');
        Route::delete('/{team}/anggota/{user}', [TeamMemberController::class, 'destroy'])->name('members.destroy');
    });
