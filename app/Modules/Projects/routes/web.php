<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Projects\Http\Controllers\ActivityLogController;
use App\Modules\Projects\Http\Controllers\MilestoneController;
use App\Modules\Projects\Http\Controllers\PipelineStageController;
use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Modules\Projects\Http\Controllers\SubProjectController;
use App\Modules\Projects\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'password.changed'])->group(function () {
    Route::middleware('permission:'.Permission::ViewProjects->value.'|'.Permission::ManageProjects->value)
        ->prefix('proyek')
        ->name('projects.')
        ->group(function () {
            Route::get('/', [ProjectController::class, 'index'])->name('index');
            Route::get('/saya', [ProjectController::class, 'mine'])->name('mine');
            Route::get('/{project}', [ProjectController::class, 'show'])->name('show');

            // Sub projects and the tasks added to them (docs/13). Tasks can be proposed by members, so this is
            // not behind projects.manage; the policies decide per action.
            Route::scopeBindings()->group(function () {
                Route::get('/{project}/sub/{subProject}', [SubProjectController::class, 'show'])->name('sub.show');
                Route::post('/{project}/sub/{subProject}/tugas', [TaskController::class, 'store'])->name('tasks.store');
            });

            Route::middleware('permission:'.Permission::ManageProjects->value)->group(function () {
                Route::post('/', [ProjectController::class, 'store'])->name('store');
                Route::put('/{project}', [ProjectController::class, 'update'])->name('update');
                Route::post('/{project}/anggota', [ProjectController::class, 'assign'])->name('assign');
                Route::delete('/{project}/anggota/{user}', [ProjectController::class, 'unassign'])->name('unassign');
                Route::post('/{project}/sub', [SubProjectController::class, 'store'])->name('sub.store');
                Route::put('/{project}/sub/{subProject}', [SubProjectController::class, 'update'])->scopeBindings()->name('sub.update');

                // Milestones (docs/14 3.2); reading them is part of the project page
                Route::scopeBindings()->group(function () {
                    Route::post('/{project}/milestone', [MilestoneController::class, 'store'])->name('milestones.store');
                    Route::put('/{project}/milestone/{milestone}', [MilestoneController::class, 'update'])->name('milestones.update');
                    Route::delete('/{project}/milestone/{milestone}', [MilestoneController::class, 'destroy'])->name('milestones.destroy');
                    Route::post('/{project}/milestone/{milestone}/selesai', [MilestoneController::class, 'complete'])->name('milestones.complete');
                });
            });
        });

    // One task: detail, decision on a proposal, timer, evidence, review
    Route::middleware('permission:'.Permission::ViewProjects->value.'|'.Permission::ManageProjects->value)
        ->prefix('tugas')
        ->name('tasks.')
        ->group(function () {
            Route::post('/timer/berhenti', [TaskController::class, 'stop'])->name('stop');
            Route::get('/bukti/{submission}', [TaskController::class, 'evidence'])->name('evidence');

            Route::whereNumber('task')->group(function () {
                Route::get('/{task}', [TaskController::class, 'show'])->name('show');
                Route::put('/{task}', [TaskController::class, 'update'])->name('update');
                Route::delete('/{task}', [TaskController::class, 'destroy'])->name('destroy');
                Route::post('/{task}/keputusan', [TaskController::class, 'decide'])->name('decide');
                Route::post('/{task}/ambil', [TaskController::class, 'claim'])->name('claim');
                Route::post('/{task}/mulai', [TaskController::class, 'start'])->name('start');
                Route::post('/{task}/kirim', [TaskController::class, 'submit'])->middleware('throttle:30,1')->name('submit');
                Route::post('/{task}/review', [TaskController::class, 'review'])->name('review');
            });
        });

    // Pipeline produksi (docs/14 3.1): Project Director and Superadmin
    Route::middleware('permission:'.Permission::ManagePipeline->value)
        ->prefix('admin/pipeline')
        ->name('admin.pipeline.')
        ->group(function () {
            Route::get('/', [PipelineStageController::class, 'index'])->name('index');
            Route::post('/', [PipelineStageController::class, 'store'])->name('store');
            Route::put('/{stage}', [PipelineStageController::class, 'update'])->name('update');
            Route::post('/{stage}/pindah', [PipelineStageController::class, 'move'])->name('move');
            Route::delete('/{stage}', [PipelineStageController::class, 'destroy'])->name('destroy');
        });

    Route::middleware('permission:'.Permission::LogActivity->value)
        ->prefix('log-kerja')
        ->name('activity.')
        ->group(function () {
            Route::get('/', [ActivityLogController::class, 'index'])->name('index');
            Route::post('/', [ActivityLogController::class, 'store'])->name('store');
            Route::put('/{activity}', [ActivityLogController::class, 'update'])->name('update');
            Route::delete('/{activity}', [ActivityLogController::class, 'destroy'])->name('destroy');
        });
});
