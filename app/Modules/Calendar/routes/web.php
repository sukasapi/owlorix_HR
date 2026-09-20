<?php

use App\Modules\Calendar\Http\Controllers\CalendarController;
use App\Modules\Calendar\Http\Controllers\CalendarDayController;
use App\Modules\Calendar\Http\Controllers\OpenedWorkdayController;
use App\Modules\Calendar\Http\Controllers\WorkWeekController;
use App\Modules\Identity\Access\Permission;
use Illuminate\Support\Facades\Route;

$viewCalendar = 'permission:'.Permission::ManageCalendar->value.'|'.Permission::OpenWorkdays->value;
$manageCalendar = 'permission:'.Permission::ManageCalendar->value;

Route::middleware(['auth', 'password.changed', $viewCalendar])->prefix('kalender')->name('calendar.')->group(function () use ($manageCalendar) {
    Route::get('/', CalendarController::class)->name('index');

    Route::middleware($manageCalendar)->group(function () {
        Route::put('/minggu-kerja', [WorkWeekController::class, 'update'])->name('work-week.update');
        Route::post('/tanggal', [CalendarDayController::class, 'store'])->name('days.store');
        Route::put('/tanggal/{calendarDay}', [CalendarDayController::class, 'update'])->name('days.update');
        Route::delete('/tanggal/{calendarDay}', [CalendarDayController::class, 'destroy'])->name('days.destroy');
    });

    // Scope (which teams and people) is checked by OpenedWorkdayPolicy.
    Route::post('/dibuka', [OpenedWorkdayController::class, 'store'])->name('opened.store');
    Route::delete('/dibuka/{openedWorkday}', [OpenedWorkdayController::class, 'destroy'])->name('opened.destroy');
});
