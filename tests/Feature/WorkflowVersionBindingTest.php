<?php

use App\Exceptions\InvalidWorkflowMutation;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\ProjectStatus;
use App\Services\WorkflowDefinitionManager;
use App\WorkflowStepKind;

/**
 * Create a running Project fixture for workflow version binding tests.
 */
function workflowBindingProject(string $name): Project
{
    return Project::create([
        'name' => $name,
        'path' => '/tmp/workflow-binding-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Create one durable Task fixture belonging to a Project.
 */
function workflowBindingTask(Project $project, string $key): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => 1,
        'title' => 'Workflow binding task',
        'objective' => 'Verify immutable workflow version binding.',
        'acceptance_criteria' => ['Binding is immutable once persisted.'],
        'verification_commands' => [],
        'implementation_prompt' => 'Implement only this bounded Task.',
        'context_capsule' => [],
    ]);
}

/**
 * Create one approved WorkflowDefinition fixture for binding tests.
 */
function workflowBindingDefinition(string $key): WorkflowDefinition
{
    $user = User::factory()->create();

    return app(WorkflowDefinitionManager::class)->createVersion(
        $user,
        $key,
        'Binding Flow',
        null,
        [['key' => 'intake', 'kind' => WorkflowStepKind::Queued, 'label' => 'Intake']],
        [],
    );
}

test('a task persists the workflow definition version selected at creation', function () {
    $project = workflowBindingProject('Binding project');
    $task = workflowBindingTask($project, 'BIND-001');
    $definition = workflowBindingDefinition('bind-flow-one');

    $bound = app(WorkflowDefinitionManager::class)->bindTaskVersion($task, $definition);

    expect($bound->workflow_definition_id)->toBe($definition->id);
    expect($task->fresh()->workflow_definition_id)->toBe($definition->id);
    expect(AuditEvent::query()->where('event_type', 'workflow.task_version_bound')->exists())->toBeTrue();
});

test('an existing task workflow version binding cannot be silently rewritten', function () {
    $project = workflowBindingProject('Rebind project');
    $task = workflowBindingTask($project, 'BIND-002');
    $first = workflowBindingDefinition('bind-flow-two');
    $second = workflowBindingDefinition('bind-flow-three');

    $manager = app(WorkflowDefinitionManager::class);
    $manager->bindTaskVersion($task, $first);

    expect(fn () => $manager->bindTaskVersion($task, $second))->toThrow(InvalidWorkflowMutation::class);

    expect($task->fresh()->workflow_definition_id)->toBe($first->id);
    expect(AuditEvent::query()->where('event_type', 'workflow.task_binding_rejected')->exists())->toBeTrue();
});

test('directly updating a bound task workflow definition id is rejected by the model guard', function () {
    $project = workflowBindingProject('Direct rewrite project');
    $task = workflowBindingTask($project, 'BIND-003');
    $first = workflowBindingDefinition('bind-flow-four');
    $second = workflowBindingDefinition('bind-flow-five');

    app(WorkflowDefinitionManager::class)->bindTaskVersion($task, $first);
    $task->refresh();

    expect(fn () => $task->update(['workflow_definition_id' => $second->id]))->toThrow(InvalidWorkflowMutation::class);
});

test('an existing task without a workflow binding preserves prior behavior and remains unbound', function () {
    $project = workflowBindingProject('Unbound project');
    $task = workflowBindingTask($project, 'BIND-004');

    expect($task->workflow_definition_id)->toBeNull();
    expect($task->fresh()->status->value)->toBe('queued');
});
