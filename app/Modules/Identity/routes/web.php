<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Http\Controllers\Admin\PeopleController;
use App\Modules\Identity\Http\Controllers\ImposterController;
use App\Modules\Identity\Http\Controllers\PasswordController;
use App\Modules\Identity\Http\Controllers\PreferencesController;
use App\Modules\Identity\Http\Controllers\SignInController;
use App\Modules\Identity\Http\Controllers\WebHandoffController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/masuk', [SignInController::class, 'create'])->name('sign-in');
    Route::post('/masuk', [SignInController::class, 'store'])->name('sign-in.store');
});

// Outside guest: may replace an existing web session with the desktop user's session.
Route::get('/masuk/dari-desktop/{token}', WebHandoffController::class)
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->middleware('throttle:30,1')
    ->name('sign-in.desktop-handoff');

Route::middleware('auth')->group(function () {
    Route::post('/keluar', [SignInController::class, 'destroy'])->name('sign-out');

    Route::get('/kata-sandi', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/kata-sandi', [PasswordController::class, 'update'])->name('password.update');

    Route::patch('/preferensi', [PreferencesController::class, 'update'])->name('preferences.update');
});

Route::middleware(['auth', 'password.changed', 'imposter.enabled'])
    ->prefix('imposter')
    ->name('imposter.')
    ->group(function () {
        Route::post('/berhenti', [ImposterController::class, 'stop'])->name('stop');

        Route::middleware('permission:'.Permission::ImpersonateUsers->value)->group(function () {
            Route::get('/', [ImposterController::class, 'index'])->name('index');
            Route::post('/{user}', [ImposterController::class, 'start'])->name('start');
        });
    });

Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ManageUsers->value])
    ->prefix('admin/orang')
    ->name('admin.people.')
    ->group(function () {
        Route::get('/', [PeopleController::class, 'index'])->name('index');
        Route::post('/', [PeopleController::class, 'store'])->name('store');
        Route::put('/{user}', [PeopleController::class, 'update'])->name('update');
        Route::post('/{user}/kata-sandi-sementara', [PeopleController::class, 'resetPassword'])->name('reset-password');
    });
