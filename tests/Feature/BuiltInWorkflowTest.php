<?php

use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\ProjectStatus;
use App\Services\BuiltInWorkflow;
use App\Services\TaskWorkflow;
use App\Services\WorkflowDefinitionManager;
use App\Services\WorkflowGraphValidator;
use App\TaskStatus;
use App\WorkflowDefinitionStatus;
use App\WorkflowStepKind;

test('the built-in workflow graph passes deterministic validation', function () {
    $result = app(WorkflowGraphValidator::class)->validate(BuiltInWorkflow::graph());

    expect($result['valid'])->toBeTrue()
        ->and($result['errors'])->toBe([])
        ->and($result['canonical_hash'])->toBeString();
});

test('the built-in workflow reproduces every TaskWorkflow transition exactly', function () {
    $graph = BuiltInWorkflow::graph();
    $graphTransitions = collect($graph['transitions'])
        ->map(fn (array $transition): string => "{$transition['from']}->{$transition['to']}")
        ->sort()
        ->values()
        ->all();

    $expectedTransitions = collect(TaskStatus::cases())
        ->flatMap(fn (TaskStatus $from): array => collect(TaskWorkflow::allowedTransitions($from))
            ->map(fn (TaskStatus $to): string => "{$from->value}->{$to->value}")
            ->all())
        ->sort()
        ->values()
        ->all();

    expect($graphTransitions)->toBe($expectedTransitions);
});

test('the built-in workflow declares exactly one step for every approved step kind', function () {
    $graph = BuiltInWorkflow::graph();
    $kinds = collect($graph['steps'])->pluck('kind')->sort()->values()->all();
    $expected = collect(WorkflowStepKind::cases())->map(fn (WorkflowStepKind $kind): string => $kind->value)->sort()->values()->all();

    expect($kinds)->toBe($expected)
        ->and($graph['entry'])->toBe(WorkflowStepKind::Queued->value);
});

test('installing the built-in workflow persists an approved immutable version reproducing the live lifecycle', function () {
    $user = User::factory()->create();

    $definition = app(WorkflowDefinitionManager::class)->installBuiltIn($user);

    expect($definition->key)->toBe(BuiltInWorkflow::Key)
        ->and($definition->version)->toBe(1)
        ->and($definition->status)->toBe(WorkflowDefinitionStatus::Approved)
        ->and($definition->steps)->toHaveCount(count(WorkflowStepKind::cases()));

    expect(AuditEvent::query()->where('event_type', 'workflow.created')->exists())->toBeTrue();
    expect(AuditEvent::query()->where('event_type', 'workflow.approved')->exists())->toBeTrue();
});

test('installing the built-in workflow twice resolves the already-installed version instead of duplicating it', function () {
    $user = User::factory()->create();
    $manager = app(WorkflowDefinitionManager::class);

    $first = $manager->installBuiltIn($user);
    $second = $manager->installBuiltIn($user);

    expect($second->id)->toBe($first->id);
    expect(WorkflowDefinition::query()->where('key', BuiltInWorkflow::Key)->count())->toBe(1);
});

test('resolveDefault returns the installed built-in definition without mutating any Task', function () {
    $user = User::factory()->create();
    $manager = app(WorkflowDefinitionManager::class);

    expect($manager->resolveDefault())->toBeNull();

    $installed = $manager->installBuiltIn($user);
    $resolved = $manager->resolveDefault();

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($installed->id);
});

test('an existing project Task remains unbound and behaves exactly as before after the built-in workflow is installed', function () {
    $user = User::factory()->create();
    app(WorkflowDefinitionManager::class)->installBuiltIn($user);

    $project = Project::create([
        'name' => 'Compatibility project',
        'path' => '/tmp/builtin-workflow-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'BUILTIN-001',
        'position' => 1,
        'title' => 'Unbound compatibility task',
        'objective' => 'Verify existing Task behavior is unaffected by built-in workflow installation.',
        'acceptance_criteria' => ['Task remains unbound and behaves exactly as before.'],
        'verification_commands' => [],
        'implementation_prompt' => 'Implement only this bounded Task.',
        'context_capsule' => [],
    ]);

    expect($task->workflow_definition_id)->toBeNull()
        ->and($task->fresh()->status)->toBe(TaskStatus::Queued);
});
