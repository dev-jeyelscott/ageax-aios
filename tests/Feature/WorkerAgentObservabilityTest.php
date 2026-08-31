<?php

use App\Actions\RunProjectManager;
use App\Actions\RunReviewerTask;
use App\AgentHarness;
use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\TaskStatus;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;

/**
 * Create a running Project for worker Agent observability coverage.
 */
function workerAgentObservabilityProject(string $name): Project
{
    return Project::create([
        'name' => $name,
        'path' => '/tmp/worker-agent-observability-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Create an enabled project Agent for one core workflow role.
 */
function workerAgentObservabilityAgent(
    Project $project,
    AgentRole $role,
    string $name,
): Agent {
    return Agent::factory()
        ->for($project)
        ->create([
            'name' => $name,
            'role' => $role,
            'harness' => AgentHarness::Codex,
            'enabled' => true,
        ]);
}

/**
 * Bind one exact primary worker slot to the supplied Agent.
 */
function workerAgentObservabilityWorker(
    Project $project,
    AgentRole $role,
    Agent $agent,
): AgentWorker {
    return AgentWorker::create([
        'project_id' => $project->id,
        'role' => $role,
        'slot' => 1,
        'agent_id' => $agent->id,
        'status' => 'idle',
    ]);
}

/**
 * Create one workflow Task for Reviewer observability coverage.
 */
function workerAgentObservabilityTask(
    Project $project,
    string $key,
    TaskStatus $status,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => 1,
        'title' => 'Worker Agent observability task',
        'objective' => 'Verify the exact executing Agent is visible.',
        'acceptance_criteria' => [
            'The CLI identifies the exact executing Agent.',
        ],
        'implementation_prompt' => 'Verify worker Agent observability.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

test('worker command prints the exact Project Manager Agent while processing a roadmap', function () {
    $project = workerAgentObservabilityProject(
        'Project Manager observability',
    );

    $agent = workerAgentObservabilityAgent(
        $project,
        AgentRole::ProjectManager,
        'Project Manager Alpha',
    );

    workerAgentObservabilityWorker(
        $project,
        AgentRole::ProjectManager,
        $agent,
    );

    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/roadmap.md',
        'status' => 'uploaded',
        'content' => '# Test roadmap',
    ]);

    $this->mock(
        RunProjectManager::class,
        function (MockInterface $mock): void {
            $mock->shouldReceive('handle')
                ->once()
                ->andReturn([
                    'exit_code' => 0,
                    'output' => '',
                    'error_output' => '',
                ]);
        },
    );

    expect(
        Artisan::call('aios:work', ['--once' => true]),
    )->toBe(0);

    expect(Artisan::output())
        ->toContain(
            "Processing roadmap {$roadmap->id} for project_manager [agent: Project Manager Alpha].",
        );
});

test('worker command prints the exact Reviewer Agent while processing a Task', function () {
    $project = workerAgentObservabilityProject(
        'Reviewer observability',
    );

    $agent = workerAgentObservabilityAgent(
        $project,
        AgentRole::Reviewer,
        'Reviewer Alpha',
    );

    workerAgentObservabilityWorker(
        $project,
        AgentRole::Reviewer,
        $agent,
    );

    $task = workerAgentObservabilityTask(
        $project,
        'TASK-REVIEWER-AGENT',
        TaskStatus::Reviewing,
    );

    TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'status' => 'running',
        'started_at' => now(),
    ]);

    $this->mock(
        RunReviewerTask::class,
        function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn([
                    'exit_code' => 0,
                    'output' => '',
                    'error_output' => '',
                ]);
        },
    );

    expect(
        Artisan::call('aios:work', ['--once' => true]),
    )->toBe(0);

    expect(Artisan::output())
        ->toContain(
            'Processing TASK-REVIEWER-AGENT for reviewer [agent: Reviewer Alpha].',
        );
});

test('a role-scoped worker does not claim another role task lane', function () {
    $project = workerAgentObservabilityProject('Role-scoped worker');
    $agent = workerAgentObservabilityAgent($project, AgentRole::Reviewer, 'Reviewer Alpha');
    workerAgentObservabilityWorker($project, AgentRole::Reviewer, $agent);

    $task = workerAgentObservabilityTask($project, 'TASK-ROLE-SCOPED', TaskStatus::Reviewing);
    TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'status' => 'running',
        'started_at' => now(),
    ]);

    $this->mock(RunReviewerTask::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('run');
    });

    expect(Artisan::call('aios:work', ['--once' => true, '--role' => AgentRole::Coder->value]))
        ->toBe(0)
        ->and($task->refresh()->status)->toBe(TaskStatus::Reviewing);
});

test('worker command rejects unsupported role scopes', function () {
    expect(Artisan::call('aios:work', ['--once' => true, '--role' => 'orchestrator']))
        ->toBe(1)
        ->and(Artisan::output())->toContain('The --role option must be project_manager, coder, or reviewer.');
});
