<?php

use App\Services\ContextCostEstimator;

function estimatorSkill(string $slug, int $position, string $instructions, string $constraints = ''): array
{
    return ['slug' => $slug, 'position' => $position, 'instructions' => $instructions, 'constraints' => $constraints];
}

test('the estimate is deterministic for identical input', function () {
    $estimator = app(ContextCostEstimator::class);
    $skills = [estimatorSkill('coder-skill', 0, 'Prefer small diffs.')];
    $taskContext = ['task_key' => 'TASK-001', 'objective' => 'Do the thing.'];

    $first = $estimator->estimate('System rules.', ['default_context' => 'Be careful.'], $skills, $taskContext);
    $second = $estimator->estimate('System rules.', ['default_context' => 'Be careful.'], $skills, $taskContext);

    expect($first)->toBe($second);
});

test('section totals sum to the reported total', function () {
    $estimator = app(ContextCostEstimator::class);
    $skills = [estimatorSkill('coder-skill', 0, 'Prefer small diffs.', 'Never bypass validation.')];
    $taskContext = [
        'task_key' => 'TASK-001',
        'obsidian_project_knowledge' => ['STATE.md' => 'Project state notes.'],
        'previous_attempt' => ['number' => 1, 'status' => 'failed'],
        'review_findings' => [['severity' => 'high']],
    ];

    $estimate = $estimator->estimate('System rules.', ['default_context' => 'Be careful.'], $skills, $taskContext);

    $expectedCharacters = $estimate['system_rules']['characters']
        + $estimate['agent_default_context']['characters']
        + $estimate['skills_total']['characters']
        + $estimate['task_core']['characters']
        + $estimate['obsidian_context']['characters']
        + $estimate['retry_recovery_evidence']['characters']
        + $estimate['review_evidence']['characters'];

    $expectedTokens = $estimate['system_rules']['estimated_tokens']
        + $estimate['agent_default_context']['estimated_tokens']
        + $estimate['skills_total']['estimated_tokens']
        + $estimate['task_core']['estimated_tokens']
        + $estimate['obsidian_context']['estimated_tokens']
        + $estimate['retry_recovery_evidence']['estimated_tokens']
        + $estimate['review_evidence']['estimated_tokens'];

    expect($estimate['total']['characters'])->toBe($expectedCharacters)
        ->and($estimate['total']['estimated_tokens'])->toBe($expectedTokens);
});

test('per skill breakdown preserves the deterministic pivot order', function () {
    $estimator = app(ContextCostEstimator::class);
    $skills = [
        estimatorSkill('universal-skill', 0, 'Universal instructions.'),
        estimatorSkill('coder-skill', 1, 'Coder specific instructions, somewhat longer.'),
    ];

    $estimate = $estimator->estimate('Rules.', [], $skills, []);

    expect(collect($estimate['skills'])->pluck('slug')->all())->toBe(['universal-skill', 'coder-skill'])
        ->and(collect($estimate['skills'])->pluck('position')->all())->toBe([0, 1])
        ->and($estimate['skills'][1]['characters'])->toBeGreaterThan($estimate['skills'][0]['characters']);
});

test('task context is bucketed into obsidian, retry recovery, review, and task core without dropping keys', function () {
    $estimator = app(ContextCostEstimator::class);
    $taskContext = [
        'task_key' => 'TASK-001',
        'objective' => 'Implement the feature.',
        'obsidian_project_knowledge' => ['STATE.md' => 'Bounded project notes.'],
        'previous_attempt' => ['number' => 2, 'status' => 'failed'],
        'review_findings' => [['severity' => 'medium']],
        'operator_messages' => [['id' => 1, 'body' => 'Please prioritize this.']],
    ];

    $estimate = $estimator->estimate('Rules.', [], [], $taskContext);

    expect($estimate['obsidian_context']['characters'])->toBeGreaterThan(0)
        ->and($estimate['retry_recovery_evidence']['characters'])->toBeGreaterThan(0)
        ->and($estimate['review_evidence']['characters'])->toBeGreaterThan(0)
        ->and($estimate['task_core']['characters'])->toBeGreaterThan(0);
});

test('a dominant section is flagged as disproportionate once it crosses the configured share', function () {
    config()->set('aios.context_cost_warning_share', 0.5);
    $estimator = app(ContextCostEstimator::class);

    $estimate = $estimator->estimate(
        'Rules.',
        [],
        [],
        ['obsidian_project_knowledge' => str_repeat('Oversized retrieval content. ', 200)],
    );

    expect($estimate['disproportionate_sections'])->toBe(['obsidian_context']);
});

test('no section is flagged when the breakdown is balanced or empty', function () {
    $estimator = app(ContextCostEstimator::class);

    $balanced = $estimator->estimate(str_repeat('a', 40), ['default_context' => str_repeat('b', 40)], [], []);
    $empty = $estimator->estimate('', [], [], []);

    expect($balanced['disproportionate_sections'])->toBe([])
        ->and($empty['total']['characters'])->toBe(0)
        ->and($empty['disproportionate_sections'])->toBe([]);
});
