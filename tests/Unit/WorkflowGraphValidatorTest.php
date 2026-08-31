<?php

use App\Services\WorkflowGraphValidator;
use App\WorkflowStepKind;

/**
 * Return the minimal valid workflow graph fixture used across validator tests.
 *
 * @return array{entry: string, steps: list<array{key: string, kind: string, label: string}>, transitions: list<array{from: string, to: string}>}
 */
function validGraphFixture(): array
{
    return [
        'entry' => 'intake',
        'steps' => [
            ['key' => 'intake', 'kind' => WorkflowStepKind::Queued->value, 'label' => 'Intake'],
            ['key' => 'build', 'kind' => WorkflowStepKind::Coding->value, 'label' => 'Build'],
            ['key' => 'complete', 'kind' => WorkflowStepKind::Done->value, 'label' => 'Complete'],
        ],
        'transitions' => [
            ['from' => 'intake', 'to' => 'build'],
            ['from' => 'build', 'to' => 'complete'],
        ],
    ];
}

test('a valid graph passes validation and produces a canonical representation with a stable hash', function () {
    $result = app(WorkflowGraphValidator::class)->validate(validGraphFixture());

    expect($result['valid'])->toBeTrue()
        ->and($result['errors'])->toBe([])
        ->and($result['canonical']['entry'])->toBe('intake')
        ->and($result['canonical']['steps'])->toHaveCount(3)
        ->and($result['canonical']['transitions'])->toHaveCount(2)
        ->and($result['canonical_hash'])->toBeString();
});

test('equivalent graph input in a different declaration order produces the same canonical representation and hash', function () {
    $validator = app(WorkflowGraphValidator::class);
    $fixture = validGraphFixture();

    $reordered = [
        'entry' => $fixture['entry'],
        'steps' => array_reverse($fixture['steps']),
        'transitions' => array_reverse($fixture['transitions']),
    ];

    $first = $validator->validate($fixture);
    $second = $validator->validate($reordered);

    expect($second['valid'])->toBeTrue()
        ->and($second['canonical'])->toBe($first['canonical'])
        ->and($second['canonical_hash'])->toBe($first['canonical_hash']);
});

test('a graph without a declared entry step is rejected with a stable reason', function () {
    $graph = validGraphFixture();
    $graph['entry'] = 'nonexistent';

    $result = app(WorkflowGraphValidator::class)->validate($graph);

    expect($result['valid'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('MISSING_ENTRY_STEP');
});

test('a graph without any terminal step kind is rejected with a stable reason', function () {
    $graph = validGraphFixture();
    $graph['steps'] = [
        ['key' => 'intake', 'kind' => WorkflowStepKind::Queued->value, 'label' => 'Intake'],
        ['key' => 'build', 'kind' => WorkflowStepKind::Coding->value, 'label' => 'Build'],
    ];
    $graph['transitions'] = [['from' => 'intake', 'to' => 'build']];

    $result = app(WorkflowGraphValidator::class)->validate($graph);

    expect($result['valid'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('MISSING_TERMINAL_STEP');
});

test('a step unreachable from the entry step is rejected with a stable reason', function () {
    $graph = validGraphFixture();
    $graph['steps'][] = ['key' => 'orphan', 'kind' => WorkflowStepKind::Blocked->value, 'label' => 'Orphan'];

    $result = app(WorkflowGraphValidator::class)->validate($graph);

    expect($result['valid'])->toBeFalse();

    $unreachable = collect($result['errors'])->firstWhere('code', 'UNREACHABLE_STEP');
    expect($unreachable['context']['step'])->toBe('orphan');
});

test('a transition referencing an undeclared step is rejected with a stable reason', function () {
    $graph = validGraphFixture();
    $graph['transitions'][] = ['from' => 'build', 'to' => 'ghost'];

    $result = app(WorkflowGraphValidator::class)->validate($graph);

    expect($result['valid'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('INVALID_STEP_REFERENCE');
});

test('a step declaring a disallowed step kind is rejected with a stable reason', function () {
    $graph = validGraphFixture();
    $graph['steps'][] = ['key' => 'rogue', 'kind' => 'run_arbitrary_shell', 'label' => 'Rogue'];
    $graph['transitions'][] = ['from' => 'intake', 'to' => 'rogue'];

    $result = app(WorkflowGraphValidator::class)->validate($graph);

    expect($result['valid'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('DISALLOWED_STEP_KIND');
});

test('two steps mapping to the same step kind produce an ambiguous mapping rejection', function () {
    $graph = validGraphFixture();
    $graph['steps'][] = ['key' => 'build-again', 'kind' => WorkflowStepKind::Coding->value, 'label' => 'Build Again'];
    $graph['transitions'][] = ['from' => 'intake', 'to' => 'build-again'];

    $result = app(WorkflowGraphValidator::class)->validate($graph);

    expect($result['valid'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('AMBIGUOUS_STEP_KIND_MAPPING');
});

test('a cyclic component with no reachable terminal step is rejected as an unbounded cycle', function () {
    $graph = [
        'entry' => 'a',
        'steps' => [
            ['key' => 'a', 'kind' => WorkflowStepKind::Queued->value, 'label' => 'A'],
            ['key' => 'b', 'kind' => WorkflowStepKind::Coding->value, 'label' => 'B'],
        ],
        'transitions' => [
            ['from' => 'a', 'to' => 'b'],
            ['from' => 'b', 'to' => 'a'],
        ],
    ];

    $result = app(WorkflowGraphValidator::class)->validate($graph);

    expect($result['valid'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('UNBOUNDED_CYCLE_NO_ESCAPE');
});

test('a cyclic component with a reachable terminal step is accepted as a bounded repetition loop', function () {
    $graph = [
        'entry' => 'a',
        'steps' => [
            ['key' => 'a', 'kind' => WorkflowStepKind::Queued->value, 'label' => 'A'],
            ['key' => 'b', 'kind' => WorkflowStepKind::Coding->value, 'label' => 'B'],
            ['key' => 'c', 'kind' => WorkflowStepKind::Done->value, 'label' => 'C'],
        ],
        'transitions' => [
            ['from' => 'a', 'to' => 'b'],
            ['from' => 'b', 'to' => 'a'],
            ['from' => 'b', 'to' => 'c'],
        ],
    ];

    $result = app(WorkflowGraphValidator::class)->validate($graph);

    expect($result['valid'])->toBeTrue();
});

test('a cyclic component exceeding the fixed system cycle-size bound is rejected regardless of graph input', function () {
    // A single ring using every approved step kind exactly once (so no step kind is ambiguously
    // mapped) still exceeds the validator's fixed, non-negotiable cycle-size bound.
    $kinds = WorkflowStepKind::cases();
    $steps = array_map(
        fn (WorkflowStepKind $kind, int $index): array => ['key' => "ring-{$index}", 'kind' => $kind->value, 'label' => "Ring {$index}"],
        $kinds,
        array_keys($kinds),
    );

    $transitions = [];
    $count = count($steps);

    foreach ($steps as $index => $step) {
        $transitions[] = ['from' => $step['key'], 'to' => $steps[($index + 1) % $count]['key']];
    }

    $graph = ['entry' => 'ring-0', 'steps' => $steps, 'transitions' => $transitions];

    $result = app(WorkflowGraphValidator::class)->validate($graph);

    expect($result['valid'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('UNBOUNDED_CYCLE_EXCEEDS_LIMIT');
});
