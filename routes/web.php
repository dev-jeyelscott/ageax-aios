<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GlobalAgentController;
use App\Http\Controllers\HarnessScorecardController;
use App\Http\Controllers\KnowledgeImprovementController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectWorkflowController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketEscalationDecisionController;
use App\Http\Controllers\TicketOperationsController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::resource('projects', ProjectController::class)->only(['index', 'store', 'show', 'destroy']);

    Route::get(
        'projects/{project}/workflow',
        ProjectWorkflowController::class,
    )->name('projects.workflow');

    Route::get('agents', [GlobalAgentController::class, 'index'])->name('agents.index');
    Route::get('agents/{agent}', [GlobalAgentController::class, 'show'])->name('agents.show');
    Route::patch('agents/{agent}', [GlobalAgentController::class, 'update'])->name('agents.update');
    Route::post('agents/{agent}/invoke', [GlobalAgentController::class, 'invoke'])->name('agents.invoke');
    Route::get('agents/{agent}/runs/{run}', [GlobalAgentController::class, 'showRun'])->scopeBindings()->name('agents.runs.show');

    Route::get('ticket-operations', [TicketOperationsController::class, 'index'])->name('ticket-operations.index');
    Route::get('ticket-operations/{ticket}', [TicketOperationsController::class, 'show'])->name('ticket-operations.show');

    Route::get('harness-scorecards', [HarnessScorecardController::class, 'index'])->name('harness-scorecards.index');

    Route::patch('projects/{project}/status', [ProjectController::class, 'updateStatus'])->name('projects.status.update');
    Route::get('projects/{project}/tasks/{task}', [ProjectController::class, 'showTask'])->scopeBindings()->name('projects.tasks.show');
    Route::get('projects/{project}/agent-runs/{run}', [ProjectController::class, 'showAgentRun'])->scopeBindings()->name('projects.agent-runs.show');
    Route::post('projects/{project}/project-manager-messages', [ProjectController::class, 'storeProjectManagerMessage'])->name('projects.project-manager-messages.store');
    Route::post('projects/{project}/tasks/{task}/operator-messages', [ProjectController::class, 'storeOperatorMessage'])->scopeBindings()->name('projects.tasks.operator-messages.store');
    Route::post('projects/{project}/tasks/{task}/requeue', [ProjectController::class, 'requeueTask'])->scopeBindings()->name('projects.tasks.requeue');
    Route::post('projects/{project}/roadmaps', [ProjectController::class, 'storeRoadmap'])->name('projects.roadmaps.store');
    Route::post('projects/{project}/roadmaps/{roadmap}/requeue', [ProjectController::class, 'requeueRoadmap'])->scopeBindings()->name('projects.roadmaps.requeue');

    Route::get('projects/{project}/tickets', [TicketController::class, 'index'])->name('projects.tickets.index');
    Route::post('projects/{project}/tickets', [TicketController::class, 'store'])->name('projects.tickets.store');
    Route::get('projects/{project}/tickets/{ticket}', [TicketController::class, 'show'])->scopeBindings()->name('projects.tickets.show');
    Route::post('projects/{project}/tickets/{ticket}/messages', [TicketController::class, 'storeMessage'])->scopeBindings()->name('projects.tickets.messages.store');

    Route::post(
        'projects/{project}/tickets/{ticket}/triage-attempts/{triageAttempt}/operator-decision',
        TicketEscalationDecisionController::class,
    )->scopeBindings()->name('projects.tickets.escalation-decisions.store');

    Route::get(
        'projects/{project}/knowledge-improvements',
        [KnowledgeImprovementController::class, 'index'],
    )->name('projects.knowledge-improvements.index');

    Route::patch(
        'projects/{project}/knowledge-improvements/{candidate}',
        [KnowledgeImprovementController::class, 'decide'],
    )->name('projects.knowledge-improvements.decide');

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
