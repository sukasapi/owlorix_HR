<?php

use App\Modules\Attendance\Http\Controllers\IdleAnswerController;
use App\Modules\Attendance\Http\Controllers\Team\IdleReviewController;
use App\Modules\Attendance\Http\Controllers\Team\TeamIdleController;
use App\Modules\Identity\Access\Permission;
use Illuminate\Support\Facades\Route;

// Tim hari ini, tab PC diam: quiet periods of the team with the lead's review (2026-09-25).
Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ViewTeamBoard->value])
    ->prefix('tim-hari-ini/pc-diam')
    ->group(function () {
        Route::get('/', TeamIdleController::class)->name('team.idle');
        Route::post('/cek', [IdleReviewController::class, 'check'])->name('team.idle.check');
        Route::post('/tanya', [IdleReviewController::class, 'ask'])->name('team.idle.ask');
    });

// The person answers a question about their own quiet period.
Route::middleware(['auth', 'password.changed', 'permission:'.Permission::ClockIn->value])
    ->post('/pc-diam/{review}/jawab', IdleAnswerController::class)
    ->whereNumber('review')
    ->name('idle-reviews.answer');
