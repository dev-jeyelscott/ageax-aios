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

test('the project office exposes the latest safe agent message and immutable execution identity for live workflow bubbles', function () {
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
        'harness' => 'claude_code',
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', 'command-center'),
        'configuration_snapshot' => [
            'context_schema_version' => 2,
            'context_hash' => hash('sha256', 'command-center-context'),
            'agent' => [
                'id' => 42,
                'name' => 'Snapshot Coder',
                'role' => AgentRole::Coder->value,
                'harness' => 'claude_code',
                'model' => 'claude-sonnet-5',
                'reasoning_setting' => 'high',
                'configuration_version' => 7,
            ],
            'skills' => [],
        ],
        'context_schema_version' => 2,
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
            ->where(
                'project.office_workers.0.run.configuration.harness',
                'claude_code',
            )
            ->where(
                'project.office_workers.0.run.configuration.model',
                'claude-sonnet-5',
            )
            ->where(
                'project.office_workers.0.run.configuration.reasoning_setting',
                'high',
            )
            ->where(
                'project.office_workers.0.run.configuration.configuration_version',
                7,
            )
            ->where(
                'project.office_workers.0.run.configuration.source',
                'snapshot',
            )
            ->missing('project.office_workers.0.run.live_output'));
});

test('the project office command center matches the workflow first sixty forty operational layout', function () {
    $office = file_get_contents(
        resource_path('js/components/agent-office.tsx'),
    );
    $officeStyles = file_get_contents(
        resource_path('js/components/agent-office.css'),
    );
    $controller = file_get_contents(
        app_path('Http/Controllers/ProjectController.php'),
    );

    expect($office)
        ->toContain('AI Engineering Workflow')
        ->toContain('Deterministic workflow with verifiable AIOS')
        ->toContain(
            "const preferredRoleOrder = ['project_manager', 'coder', 'reviewer'] as const;",
        )
        ->toContain('execution-workflow-panel')
        ->toContain('execution-operations-panel')
        ->toContain('aria-label="Operational intelligence"')
        ->toContain('pmToCoderState')
        ->toContain('coderToReviewerState')
        ->toContain("currentWorkflow?.role === 'coder'")
        ->toContain("currentWorkflow?.role === 'reviewer'")
        ->toContain("pmToCoderState = 'active'")
        ->toContain("coderToReviewerState = 'active'")
        ->toContain("pmToCoderState = 'complete'")
        ->toContain("pmToCoderState = 'paused'")
        ->toContain("coderToReviewerState = 'paused'")
        ->toContain('data-connector-state={state}')
        ->toContain('worker.run?.latest_message')
        ->toContain('aria-live={active')
        ->toContain('/action-gif/pm-idle.gif')
        ->toContain('/action-gif/coder-idle.gif')
        ->toContain('/action-gif/reviewer-idle.gif')
        ->toContain('Harness / Model')
        ->toContain('worker.run?.configuration')
        ->toContain("source: 'bound_agent'")
        ->toContain('if (agent) {')
        ->toContain('const runConfiguration = worker.run?.configuration;')
        ->toContain("? 'Recent Task'")
        ->toContain('Roadmap Actions')
        ->toContain('requeueRoadmap.form({')
        ->toContain('storeRoadmap.form(projectId)')
        ->toContain('Upload new roadmap')
        ->toContain('Execution Context')
        ->toContain('Task Progress')
        ->toContain('Active Run')
        ->toContain('Last Handoff')
        ->toContain('Workflow Scope')
        ->toContain('Deterministic Mode')
        ->toContain('Handoff Policy')
        ->toContain('Current Operation')
        ->toContain('Next Stage')
        ->toContain('Repository · Git Evidence')
        ->toContain('Validation State')
        ->toContain('Static Analysis')
        ->toContain('Execution / Token Usage')
        ->toContain('Health & Warnings')
        ->toContain('Blocked')
        ->toContain('data-aios-execution-office')
        ->toContain('data-active-stage=')
        ->not->toContain('AgeaxRobotVisual')
        ->not->toContain('AgeaxRobot')
        ->not->toContain('@react-three')
        ->not->toContain('<Canvas');

    expect($controller)
        ->toContain("'configuration_snapshot'")
        ->toContain("'configuration' => \$this->officeRunConfiguration(\$run)")
        ->toContain("'source' => 'snapshot'");

    expect($officeStyles)
        ->toContain(
            ":has(> div > [data-aios-execution-office='true'])",
        )
        ->toContain('.execution-workflow-panel')
        ->toContain('.execution-operations-panel')
        ->toContain('.execution-operations-pair')
        ->toContain(
            'grid-template-columns: minmax(0, 3fr) minmax(24rem, 2fr);',
        )
        ->toContain('.execution-roadmap-actions')
        ->toContain('.execution-workflow-grid')
        ->toContain('.execution-context-panel')
        ->toContain('.execution-git-grid')
        ->toContain('.execution-validation-grid')
        ->toContain('.execution-health-grid')
        ->toContain('.workflow-connector--active')
        ->toContain('.workflow-connector--complete')
        ->toContain('.workflow-connector--paused')
        ->toContain('execution-energy-flow')
        ->toContain('execution-particle-flow')
        ->toContain('@media (max-width: 79.999rem)')
        ->toContain('grid-template-columns: minmax(0, 1fr);')
        ->toContain('@media (max-width: 63.999rem)')
        ->toContain('@media (max-width: 47.999rem)')
        ->toContain('@media (prefers-reduced-motion: reduce)');
});
