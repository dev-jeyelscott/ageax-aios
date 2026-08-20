<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\User;
use App\ProjectStatus;
use App\Services\AgentRunRecorder;
use Inertia\Testing\AssertableInertia as Assert;

test('the project office exposes the latest safe agent message for live workflow bubbles', function () {
    $user = User::factory()->create();
    $project = Project::create([
        'name' => 'Command Center Example',
        'path' => '/tmp/command-center-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $worker = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => 'working',
        'last_heartbeat_at' => now(),
        'lease_expires_at' => now()->addMinute(),
    ]);

    $run = AgentRun::create([
        'project_id' => $project->id,
        'agent_worker_id' => $worker->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', 'command-center'),
        'started_at' => now(),
    ]);

    app(AgentRunRecorder::class)->appendLiveOutput(
        $run,
        'stdout',
        json_encode([
            'type' => 'item.completed',
            'item' => [
                'type' => 'agent_message',
                'text' => 'Inspecting the project execution UI.',
            ],
        ], JSON_THROW_ON_ERROR).PHP_EOL,
    );

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.office_workers.0.role', 'coder')
            ->where('project.office_workers.0.run.id', $run->id)
            ->where(
                'project.office_workers.0.run.latest_message',
                'Inspecting the project execution UI.',
            )
            ->missing('project.office_workers.0.run.live_output'));
});

test('the project office command center keeps the graph lightweight state driven and motion safe', function () {
    $office = file_get_contents(resource_path('js/components/agent-office.tsx'));
    $officeStyles = file_get_contents(resource_path('js/components/agent-office.css'));
    $globalStyles = file_get_contents(resource_path('css/app.css'));

    expect($office)
        ->toContain('AI Engineering Workflow')
        ->toContain('pmToCoderActive')
        ->toContain('coderToReviewerActive')
        ->toContain('pipeline-flow')
        ->toContain('worker.run?.latest_message')
        ->toContain('aria-live={active')
        ->toContain('/action-gif/pm-idle.gif')
        ->toContain('/action-gif/coder-idle.gif')
        ->toContain('/action-gif/reviewer-idle.gif')
        ->toContain('data-aios-execution-office')
        ->not->toContain('AgeaxRobotVisual')
        ->not->toContain('@react-three');

    expect($officeStyles)
        ->toContain(':has(> div > [data-aios-execution-office])')
        ->toContain('grid-template-columns: repeat(3, minmax(0, 1fr))');

    expect($globalStyles)
        ->toContain('@media (prefers-reduced-motion: reduce)')
        ->toContain('.pipeline-flow');
});
