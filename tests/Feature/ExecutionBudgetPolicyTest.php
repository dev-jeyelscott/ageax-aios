<?php

use App\AgentRole;
use App\Models\Agent;
use App\Models\Project;
use App\Models\Task;
use App\ProjectStatus;
use App\Services\AgentContextAssembler;
use App\Services\ExecutionBudgetPolicy;
use App\TaskComplexity;
use App\TaskStatus;

test('low complexity Coder tasks receive a bounded immutable execution setting', function () {
    config()->set('aios.execution_timeout', 1800);
    config()->set('aios.coder_low_complexity_execution_timeout', 180);

    $project = Project::create([
        'name' => 'Execution budget',
        'path' => '/tmp/execution-budget-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Keep the Coder bounded',
        'objective' => 'Limit a simple task.',
        'acceptance_criteria' => ['The attempt has a persisted execution limit.'],
        'implementation_prompt' => 'Implement the focused timeout policy.',
        'context_capsule' => [],
        'complexity' => TaskComplexity::Low,
        'status' => TaskStatus::Coding,
    ]);
    $agent = Agent::factory()->for($project)->create(['role' => AgentRole::Coder]);

    $settings = app(ExecutionBudgetPolicy::class)->forCoderTask($task);
    $context = app(AgentContextAssembler::class)
        ->assemble($agent, AgentRole::Coder, ['objective' => 'Make one focused change.'])
        ->withExecutionSettings($settings);
    $restored = app(AgentContextAssembler::class)->restore(
        $context->configurationSnapshot(),
        ['objective' => 'Make one focused change.'],
    );

    expect($settings)->toBe([
        'schema_version' => 1,
        'method' => 'low_complexity_timeout_v1',
        'max_execution_seconds' => 180,
    ])->and($context->toArray()['execution_settings'])->toBe($settings)
        ->and($restored->executionSettings)->toBe($settings);
});

test('non-low Coder tasks retain the global execution limit', function () {
    config()->set('aios.execution_timeout', 1800);
    config()->set('aios.coder_low_complexity_execution_timeout', 180);

    $project = Project::create([
        'name' => 'Global execution budget',
        'path' => '/tmp/global-execution-budget-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Keep the normal Coder limit',
        'objective' => 'Leave normal work at the global limit.',
        'acceptance_criteria' => ['The global timeout applies.'],
        'implementation_prompt' => 'Keep the global timeout policy.',
        'context_capsule' => [],
        'complexity' => TaskComplexity::Medium,
        'status' => TaskStatus::Coding,
    ]);

    expect(app(ExecutionBudgetPolicy::class)->forCoderTask($task))->toMatchArray([
        'method' => 'global_timeout_v1',
        'max_execution_seconds' => 1800,
    ]);
});
