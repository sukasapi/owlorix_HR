<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Overtime\Http\Controllers\ApprovalsController;
use Illuminate\Support\Facades\Route;

// First decisions need overtime.approve; changing a decision needs overtime.change_decisions. DecideOvertime checks
// the scope of each request (3.4.4, 3.4.7).
$openInbox = 'permission:'.Permission::ApproveOvertime->value.'|'.Permission::ChangeOvertimeDecisions->value;

Route::middleware(['auth', 'password.changed', $openInbox])->prefix('persetujuan')->name('approvals.')->group(function () {
    Route::get('/', [ApprovalsController::class, 'index'])->name('index');
    Route::post('/setujui-terpilih', [ApprovalsController::class, 'bulk'])
        ->middleware('permission:'.Permission::ApproveOvertime->value)
        ->name('bulk');
    Route::post('/{overtimeRequest}/keputusan', [ApprovalsController::class, 'decide'])->whereNumber('overtimeRequest')->name('decide');
});
