<?php

use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::resource('projects', ProjectController::class)->only(['index', 'store', 'show']);
    Route::patch('projects/{project}/status', [ProjectController::class, 'updateStatus'])->name('projects.status.update');
    Route::get('projects/{project}/tasks/{task}', [ProjectController::class, 'showTask'])->scopeBindings()->name('projects.tasks.show');
    Route::post('projects/{project}/tasks/{task}/operator-messages', [ProjectController::class, 'storeOperatorMessage'])->scopeBindings()->name('projects.tasks.operator-messages.store');
    Route::post('projects/{project}/tasks/{task}/requeue', [ProjectController::class, 'requeueTask'])->scopeBindings()->name('projects.tasks.requeue');
    Route::post('projects/{project}/roadmaps', [ProjectController::class, 'storeRoadmap'])->name('projects.roadmaps.store');
});

require __DIR__.'/settings.php';
