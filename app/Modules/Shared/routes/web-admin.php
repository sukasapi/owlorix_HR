<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Shared\Http\Controllers\Admin\AuditLogController;
use App\Modules\Shared\Http\Controllers\Admin\SettingsController;
use Illuminate\Support\Facades\Route;

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
