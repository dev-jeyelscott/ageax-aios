<?php

use App\Exceptions\InvalidWorkflowMutation;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Services\WorkflowDefinitionManager;
use App\TaskStatus;
use App\WorkflowDefinitionStatus;
use App\WorkflowStepKind;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Return the minimal declarative step/transition contract used across workflow definition tests.
 *
 * @return array{steps: list<array{key: string, kind: WorkflowStepKind, label: string}>, transitions: list<array{from: string, to: string}>}
 */
function workflowDefinitionContract(): array
{
    return [
        'steps' => [
            ['key' => 'intake', 'kind' => WorkflowStepKind::Queued, 'label' => 'Intake'],
            ['key' => 'build', 'kind' => WorkflowStepKind::Coding, 'label' => 'Build'],
            ['key' => 'done', 'kind' => WorkflowStepKind::Done, 'label' => 'Done'],
        ],
        'transitions' => [
            ['from' => 'intake', 'to' => 'build'],
            ['from' => 'build', 'to' => 'done'],
        ],
    ];
}

test('a verified user can persist a new workflow definition with immutable steps and transitions', function () {
    $user = User::factory()->create();
    $contract = workflowDefinitionContract();

    $definition = app(WorkflowDefinitionManager::class)->createVersion(
        $user,
        'default-task-flow',
        'Default Task Flow',
        'Baseline compatibility workflow.',
        $contract['steps'],
        $contract['transitions'],
    );

    expect($definition->key)->toBe('default-task-flow')
        ->and($definition->version)->toBe(1)
        ->and($definition->status)->toBe(WorkflowDefinitionStatus::Draft)
        ->and($definition->steps)->toHaveCount(3)
        ->and($definition->transitions)->toHaveCount(2);

    expect($definition->steps->first()->kind)->toBeInstanceOf(WorkflowStepKind::class);

    expect(AuditEvent::query()->where('event_type', 'workflow.created')->exists())->toBeTrue();
});

test('creating another version for the same key increments the version and preserves the prior version', function () {
    $user = User::factory()->create();
    $contract = workflowDefinitionContract();
    $manager = app(WorkflowDefinitionManager::class);

    $first = $manager->createVersion($user, 'iterative-flow', 'Iterative Flow', null, $contract['steps'], $contract['transitions']);
    $second = $manager->createVersion($user, 'iterative-flow', 'Iterative Flow v2', null, $contract['steps'], $contract['transitions']);

    expect($first->version)->toBe(1)
        ->and($second->version)->toBe(2)
        ->and(WorkflowDefinition::query()->where('key', 'iterative-flow')->count())->toBe(2);

    expect($first->fresh()->name)->toBe('Iterative Flow');
    expect(AuditEvent::query()->where('event_type', 'workflow.version_created')->exists())->toBeTrue();
});

test('a workflow transition cannot reference a step outside its own definition version', function () {
    $user = User::factory()->create();

    expect(fn () => app(WorkflowDefinitionManager::class)->createVersion(
        $user,
        'invalid-flow',
        'Invalid Flow',
        null,
        [['key' => 'intake', 'kind' => WorkflowStepKind::Queued, 'label' => 'Intake']],
        [['from' => 'intake', 'to' => 'nonexistent']],
    ))->toThrow(InvalidWorkflowMutation::class);

    expect(WorkflowDefinition::query()->where('key', 'invalid-flow')->exists())->toBeFalse();
    expect(AuditEvent::query()->where('event_type', 'workflow.mutation_rejected')->exists())->toBeTrue();
});

test('an unverified user cannot create a workflow definition', function () {
    $user = User::factory()->unverified()->create();
    $contract = workflowDefinitionContract();

    expect(fn () => app(WorkflowDefinitionManager::class)->createVersion(
        $user,
        'unauthorized-flow',
        'Unauthorized Flow',
        null,
        $contract['steps'],
        $contract['transitions'],
    ))->toThrow(AuthorizationException::class);

    expect(WorkflowDefinition::query()->where('key', 'unauthorized-flow')->exists())->toBeFalse();
    expect(AuditEvent::query()->where('event_type', 'workflow.mutation_rejected')->exists())->toBeTrue();
});

test('a draft workflow definition can be approved and then archived through explicit lifecycle transitions', function () {
    $user = User::factory()->create();
    $contract = workflowDefinitionContract();
    $manager = app(WorkflowDefinitionManager::class);

    $definition = $manager->createVersion($user, 'lifecycle-flow', 'Lifecycle Flow', null, $contract['steps'], $contract['transitions']);

    $approved = $manager->approve($user, $definition);
    expect($approved->status)->toBe(WorkflowDefinitionStatus::Approved)
        ->and($approved->approved_by_user_id)->toBe($user->id)
        ->and($approved->approved_at)->not->toBeNull();

    $archived = $manager->archive($user, $approved);
    expect($archived->status)->toBe(WorkflowDefinitionStatus::Archived)
        ->and($archived->archived_at)->not->toBeNull();

    expect(AuditEvent::query()->where('event_type', 'workflow.approved')->exists())->toBeTrue();
    expect(AuditEvent::query()->where('event_type', 'workflow.archived')->exists())->toBeTrue();
});

test('an already approved workflow definition cannot be approved again', function () {
    $user = User::factory()->create();
    $contract = workflowDefinitionContract();
    $manager = app(WorkflowDefinitionManager::class);

    $definition = $manager->createVersion($user, 'double-approval-flow', 'Double Approval Flow', null, $contract['steps'], $contract['transitions']);
    $approved = $manager->approve($user, $definition);

    expect(fn () => $manager->approve($user, $approved))->toThrow(AuthorizationException::class);
    expect(AuditEvent::query()->where('event_type', 'workflow.mutation_rejected')->exists())->toBeTrue();
});

test('the immutable content of a persisted workflow definition version cannot be rewritten', function () {
    $user = User::factory()->create();
    $contract = workflowDefinitionContract();
    $definition = app(WorkflowDefinitionManager::class)->createVersion($user, 'immutable-flow', 'Immutable Flow', null, $contract['steps'], $contract['transitions']);

    expect(fn () => $definition->update(['name' => 'Rewritten Name']))->toThrow(InvalidWorkflowMutation::class);
});

test('a persisted workflow step cannot be mutated', function () {
    $user = User::factory()->create();
    $contract = workflowDefinitionContract();
    $definition = app(WorkflowDefinitionManager::class)->createVersion($user, 'immutable-step-flow', 'Immutable Step Flow', null, $contract['steps'], $contract['transitions']);
    $step = $definition->steps->first();

    expect(fn () => $step->update(['label' => 'Rewritten Label']))->toThrow(InvalidWorkflowMutation::class);
});

test('every workflow step kind maps deterministically to a compatible TaskStatus', function () {
    foreach (WorkflowStepKind::cases() as $kind) {
        expect($kind->toTaskStatus())->toBeInstanceOf(TaskStatus::class)
            ->and($kind->toTaskStatus()->value)->toBe($kind->value);
    }
});
