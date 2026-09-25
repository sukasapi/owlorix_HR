<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Shared\Http\Controllers\Admin\AppSettingsController;
use App\Modules\Shared\Http\Controllers\Admin\AuditLogController;
use App\Modules\Shared\Http\Controllers\Admin\SettingsController;
use App\Modules\Shared\Http\Controllers\BrandLogoController;
use App\Modules\Shared\Http\Controllers\SettingsHubController;
use Illuminate\Support\Facades\Route;

// Pengaturan: the page that lists every admin page the person can open (403 when there is none).
Route::middleware(['auth', 'password.changed'])
    ->get('/pengaturan', SettingsHubController::class)
    ->name('settings.index');

// Aturan: rule settings (Superadmin).
Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ManageSettings->value])
    ->prefix('admin/aturan')
    ->name('admin.settings.')
    ->group(function () {
        Route::get('/', [SettingsController::class, 'edit'])->name('edit');
        Route::put('/', [SettingsController::class, 'update'])->name('update');
    });

// Log audit: read-only list of recorded changes.
Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ViewAuditLog->value])
    ->get('/admin/log-audit', AuditLogController::class)
    ->name('admin.audit.index');

// Pengaturan aplikasi: app name, studio name, footer, logo (Superadmin, docs/13).
Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ManageSettings->value])
    ->prefix('admin/aplikasi')
    ->name('admin.app-settings.')
    ->group(function () {
        Route::get('/', [AppSettingsController::class, 'edit'])->name('edit');
        Route::put('/', [AppSettingsController::class, 'update'])->name('update');
        Route::post('/logo', [AppSettingsController::class, 'storeLogo'])->middleware('throttle:20,1')->name('logo.store');
        Route::delete('/logo', [AppSettingsController::class, 'destroyLogo'])->name('logo.destroy');
    });

// The logo is public: the sign-in page and the favicon show it before sign-in.
Route::get('/merek/logo', BrandLogoController::class)->middleware('throttle:120,1')->name('brand.logo');
