<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Projects\Http\Controllers\TeamLogController;
use Illuminate\Support\Facades\Route;

// Tim hari ini, tab Log kerja: work logs of the viewer's team, read only (2026-09-25).
Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ViewTeamBoard->value])
    ->get('/tim-hari-ini/log-kerja', TeamLogController::class)
    ->name('team.logs');
