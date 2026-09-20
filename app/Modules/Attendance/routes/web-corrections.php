<?php

use App\Modules\Attendance\Http\Controllers\CorrectionsController;
use App\Modules\Identity\Access\Permission;
use Illuminate\Support\Facades\Route;

// Koreksi (docs/02 3.10). Management proposes (corrections.propose), Superadmin applies (corrections.apply). The
// scope of each person and the no-self rule are checked in CorrectionScope and CorrectionWorkflow.
$either = 'permission:'.Permission::ProposeCorrections->value.'|'.Permission::ApplyCorrections->value;
$apply = 'permission:'.Permission::ApplyCorrections->value;

Route::middleware(['auth', 'password.changed', $either])->prefix('koreksi')->name('corrections.')->group(function () use ($apply) {
    Route::get('/', [CorrectionsController::class, 'index'])->name('index');
    Route::get('/shift', [CorrectionsController::class, 'shifts'])->name('shifts');
    Route::post('/pratinjau', [CorrectionsController::class, 'preview'])->name('preview');
    Route::post('/', [CorrectionsController::class, 'store'])->name('store');
    Route::post('/{correction}/terapkan', [CorrectionsController::class, 'apply'])->middleware($apply)->whereNumber('correction')->name('apply');
    Route::post('/{correction}/tolak', [CorrectionsController::class, 'decline'])->middleware($apply)->whereNumber('correction')->name('decline');
});
