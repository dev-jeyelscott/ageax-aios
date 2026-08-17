<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\GlobalAgentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SkillController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::resource('projects', ProjectController::class)->only(['index', 'store', 'show', 'destroy']);

    Route::get('agents', [GlobalAgentController::class, 'index'])->name('agents.index');
    Route::get('agents/{agent}', [GlobalAgentController::class, 'show'])->name('agents.show');
    Route::patch('agents/{agent}', [GlobalAgentController::class, 'update'])->name('agents.update');
    Route::post('agents/{agent}/invoke', [GlobalAgentController::class, 'invoke'])->name('agents.invoke');
    Route::get('agents/{agent}/runs/{run}', [GlobalAgentController::class, 'showRun'])->scopeBindings()->name('agents.runs.show');
    Route::patch('projects/{project}/status', [ProjectController::class, 'updateStatus'])->name('projects.status.update');
    Route::get('projects/{project}/tasks/{task}', [ProjectController::class, 'showTask'])->scopeBindings()->name('projects.tasks.show');
    Route::get('projects/{project}/agent-runs/{run}', [ProjectController::class, 'showAgentRun'])->scopeBindings()->name('projects.agent-runs.show');
    Route::post('projects/{project}/project-manager-messages', [ProjectController::class, 'storeProjectManagerMessage'])->name('projects.project-manager-messages.store');
    Route::post('projects/{project}/tasks/{task}/operator-messages', [ProjectController::class, 'storeOperatorMessage'])->scopeBindings()->name('projects.tasks.operator-messages.store');
    Route::post('projects/{project}/tasks/{task}/requeue', [ProjectController::class, 'requeueTask'])->scopeBindings()->name('projects.tasks.requeue');
    Route::post('projects/{project}/roadmaps', [ProjectController::class, 'storeRoadmap'])->name('projects.roadmaps.store');

    Route::post('projects/{project}/agents', [AgentController::class, 'store'])->name('projects.agents.store');
    Route::patch('projects/{project}/agents/{agent}', [AgentController::class, 'update'])->scopeBindings()->name('projects.agents.update');
    Route::post('projects/{project}/agents/{agent}/skills', [AgentController::class, 'assignSkill'])->scopeBindings()->name('projects.agents.skills.store');
    Route::patch('projects/{project}/agents/{agent}/skills', [AgentController::class, 'reorderSkills'])->scopeBindings()->name('projects.agents.skills.reorder');
    Route::delete('projects/{project}/agents/{agent}/skills/{skill}', [AgentController::class, 'unassignSkill'])->scopeBindings()->name('projects.agents.skills.destroy');
    Route::patch('projects/{project}/agents/{agent}/worker', [AgentController::class, 'bindWorker'])->scopeBindings()->name('projects.agents.worker.update');

    Route::post('projects/{project}/skills', [SkillController::class, 'store'])->name('projects.skills.store');
    Route::patch('projects/{project}/skills/{skill}', [SkillController::class, 'update'])->scopeBindings()->name('projects.skills.update');
    Route::delete('projects/{project}/skills/{skill}', [SkillController::class, 'destroy'])->scopeBindings()->name('projects.skills.destroy');
});

require __DIR__.'/settings.php';
