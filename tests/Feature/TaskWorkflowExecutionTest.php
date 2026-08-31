<?php

use App\Exceptions\InvalidTaskTransition;
use App\Exceptions\InvalidWorkflowExecutionState;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\ProjectStatus;
use App\Services\TaskWorkflow;
use App\Services\WorkflowDefinitionManager;
use App\TaskStatus;
use App\WorkflowStepKind;

/**
 * Create a running Project fixture for bound workflow execution tests.
 */
function boundWorkflowProject(string $name): Project
{
    return Project::create([
        'name' => $name,
        'path' => '/tmp/task-workflow-execution-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Create one durable queued Task fixture bound to a workflow definition version.
 */
function boundWorkflowTask(Project $project, string $key, ?int $workflowDefinitionId = null): Task
{
    return Task::create([
        'project_id' => $project->id,
        'workflow_definition_id' => $workflowDefinitionId,
        'key' => $key,
        'position' => 1,
        'title' => 'Bound workflow execution task',
        'objective' => 'Verify AIOS-owned bound workflow execution.',
        'acceptance_criteria' => ['AIOS resolves execution from the persisted workflow binding.'],
        'verification_commands' => [],
        'implementation_prompt' => 'Implement only this bounded Task.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

/**
 * Install (idempotently) and return the approved built-in default workflow definition.
 */
function installedBuiltInWorkflow(): WorkflowDefinition
{
    return app(WorkflowDefinitionManager::class)->installBuiltIn(User::factory()->create());
}

test('a task bound to the built-in default workflow executes the normal lifecycle identically to an unbound task', function () {
    $definition = installedBuiltInWorkflow();
    $project = boundWorkflowProject('Built-in bound project');
    $task = boundWorkflowTask($project, 'EXEC-001', $definition->id);
    $workflow = app(TaskWorkflow::class);

    $workflow->transition($task, TaskStatus::Coding);
    $workflow->transition($task->fresh(), TaskStatus::ReadyForReview);
    $workflow->transition($task->fresh(), TaskStatus::Reviewing);
    $result = $workflow->transition($task->fresh(), TaskStatus::Done);

    expect($result->status)->toBe(TaskStatus::Done)
        ->and($task->auditEvents()->where('event_type', 'task.transitioned')->count())->toBe(4)
        ->and($task->auditEvents()->where('event_type', 'workflow.execution_blocked')->exists())->toBeFalse();
});

test('a task with no workflow binding preserves the exact existing built-in lifecycle behavior', function () {
    $project = boundWorkflowProject('Unbound project');
    $task = boundWorkflowTask($project, 'EXEC-002');
    $workflow = app(TaskWorkflow::class);

    $result = $workflow->transition($task, TaskStatus::Coding);

    expect($task->workflow_definition_id)->toBeNull()
        ->and($result->status)->toBe(TaskStatus::Coding);
});

test('a task execution fails closed with durable evidence when the bound workflow does not declare the requested transition', function () {
    $user = User::factory()->create();
    $definition = app(WorkflowDefinitionManager::class)->createVersion(
        $user,
        'narrow-flow',
        'Narrow Flow',
        null,
        [
            ['key' => 'intake', 'kind' => WorkflowStepKind::Queued, 'label' => 'Intake'],
            ['key' => 'build', 'kind' => WorkflowStepKind::Coding, 'label' => 'Build'],
            ['key' => 'done', 'kind' => WorkflowStepKind::Done, 'label' => 'Done'],
        ],
        [
            ['from' => 'intake', 'to' => 'build'],
            ['from' => 'build', 'to' => 'done'],
        ],
    );
    $project = boundWorkflowProject('Narrow flow project');
    $task = boundWorkflowTask($project, 'EXEC-003', $definition->id);
    $workflow = app(TaskWorkflow::class);

    $workflow->transition($task, TaskStatus::Coding);

    // ReadyForReview is legal per the static built-in matrix from Coding, but this bound
    // custom workflow never declares a ReadyForReview step, so it must fail closed.
    expect(fn () => $workflow->transition($task->fresh(), TaskStatus::ReadyForReview))
        ->toThrow(InvalidWorkflowExecutionState::class);

    $blocked = AuditEvent::query()->where('event_type', 'workflow.execution_blocked')->latest('id')->first();

    expect($task->fresh()->status)->toBe(TaskStatus::Coding)
        ->and($blocked)->not->toBeNull()
        ->and($blocked->payload['requested_status'])->toBe(TaskStatus::ReadyForReview->value);
});

test('a replayed transition against an already-applied bound workflow status is rejected, not silently reapplied', function () {
    $definition = installedBuiltInWorkflow();
    $project = boundWorkflowProject('Replay project');
    $task = boundWorkflowTask($project, 'EXEC-004', $definition->id);
    $workflow = app(TaskWorkflow::class);

    $workflow->transition($task, TaskStatus::Coding);

    expect(fn () => $workflow->transition($task->fresh(), TaskStatus::Coding))
        ->toThrow(InvalidTaskTransition::class);

    expect($task->fresh()->status)->toBe(TaskStatus::Coding);
});

test('a bound task remains recoverable through the existing interrupted and blocked transitions', function () {
    $definition = installedBuiltInWorkflow();
    $project = boundWorkflowProject('Recovery project');
    $task = boundWorkflowTask($project, 'EXEC-005', $definition->id);
    $workflow = app(TaskWorkflow::class);

    $workflow->transition($task, TaskStatus::Coding);
    $interrupted = $workflow->transition($task->fresh(), TaskStatus::Interrupted);
    $resumed = $workflow->transition($interrupted, TaskStatus::Coding);
    $blocked = $workflow->transition($resumed, TaskStatus::Blocked);
    $requeued = $workflow->transition($blocked, TaskStatus::Queued);

    expect($requeued->status)->toBe(TaskStatus::Queued);
});

test('the built-in workflow graph never rejects any statically allowed transition once bound', function () {
    $definition = installedBuiltInWorkflow();
    $project = boundWorkflowProject('Compatibility project');

    $position = 1;

    foreach (TaskStatus::cases() as $from) {
        foreach (TaskWorkflow::allowedTransitions($from) as $to) {
            $task = boundWorkflowTask($project, 'EXEC-COMPAT-'.$position, $definition->id);
            $task->forceFill(['status' => $from])->saveQuietly();
            $position++;

            app(TaskWorkflow::class)->transition($task, $to);

            expect($task->fresh()->status)->toBe($to);
        }
    }
});
