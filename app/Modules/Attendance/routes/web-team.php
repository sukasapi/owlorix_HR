<?php

use App\Modules\Attendance\Http\Controllers\TeamTodayController;
use App\Modules\Identity\Access\Permission;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ViewTeamBoard->value])->group(function () {
    Route::get('/tim-hari-ini', TeamTodayController::class)->name('team.today');
});
