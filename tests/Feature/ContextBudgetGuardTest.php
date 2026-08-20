<?php

use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\Models\Agent;
use App\Models\Project;
use App\Services\AgentContextAssembler;
use App\Services\ClaudeCodeHarness;
use App\Services\CodexHarness;
use App\Services\ContextBudgetGuard;
use App\Services\ContextBudgetPolicy;
use App\Services\ContextCostEstimator;
use Illuminate\Support\Facades\DB;

function p3013Project(string $name): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-context-budget-'.fake()->uuid(),
    ]);
}

test('harness capabilities resolve explicit model capacity and conservative null model fallback', function () {
    $project = p3013Project('Capacity project');
    $codexAgent = Agent::factory()->for($project)->create([
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => 'gpt-5.6-sol',
    ]);
    $codexDefaultAgent = Agent::factory()->for($project)->create([
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => null,
    ]);
    $claudeAgent = Agent::factory()->for($project)->create([
        'harness' => AgentHarnessIdentifier::ClaudeCode,
        'model' => 'claude-haiku-4-5-20251001',
        'reasoning_setting' => null,
    ]);

    $codex = app(CodexHarness::class)->capabilities();
    $claude = app(ClaudeCodeHarness::class)->capabilities();

    expect($codex->resolveContextCapacity($codexAgent, AgentHarnessIdentifier::Codex))
        ->toMatchArray([
            'resolved_capacity_tokens' => 1050000,
            'max_output_tokens' => 128000,
            'fallback' => false,
        ])
        ->and($codex->resolveContextCapacity($codexDefaultAgent, AgentHarnessIdentifier::Codex))
        ->toMatchArray([
            'resolved_capacity_tokens' => 200000,
            'max_output_tokens' => 64000,
            'fallback' => true,
        ])
        ->and($claude->resolveContextCapacity($claudeAgent, AgentHarnessIdentifier::ClaudeCode))
        ->toMatchArray([
            'resolved_capacity_tokens' => 200000,
            'max_output_tokens' => 64000,
            'fallback' => false,
        ]);
});

test('unknown persisted model capacity fails rather than borrowing another model capacity', function () {
    $project = p3013Project('Unknown model project');
    $agent = Agent::factory()->for($project)->create([
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => 'gpt-5.6-sol',
    ]);

    DB::table('agents')->where('id', $agent->id)->update([
        'model' => 'future-unapproved-model',
    ]);
    $agent = Agent::query()->findOrFail($agent->id);

    expect(fn () => app(CodexHarness::class)
        ->capabilities()
        ->resolveContextCapacity($agent, AgentHarnessIdentifier::Codex))
        ->toThrow(LogicException::class, 'not supported');
});

test('the policy locks 70 75 80 boundaries and reserves twenty percent', function () {
    $resolved = app(ContextBudgetPolicy::class)->resolve(
        AgentRole::Coder,
        100000,
    );

    expect($resolved['target_percent'])->toBe(70)
        ->and($resolved['warning_percent'])->toBe(75)
        ->and($resolved['hard_ceiling_percent'])->toBe(80)
        ->and($resolved['reserved_percent'])->toBe(20)
        ->and($resolved['target_tokens'])->toBe(70000)
        ->and($resolved['warning_tokens'])->toBe(75000)
        ->and($resolved['hard_ceiling_tokens'])->toBe(80000);
});

test('context above the normal target executes unchanged until the warning threshold then reduces deterministically', function () {
    $project = p3013Project('Context Budget threshold behavior');
    $agent = Agent::factory()->for($project)->create([
        'role' => AgentRole::Coder,
        'default_context' => str_repeat(
            'optional agent guidance ',
            6000,
        ),
    ]);
    $assembled = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Coder,
        [
            'task_key' => 'TASK-THRESHOLD',
            'objective' => 'Preserve the required task objective.',
            'acceptance_criteria' => [
                'The Context Budget boundary behavior remains deterministic.',
            ],
        ],
    );
    $prompt = "Coder contract.\n\n".json_encode(
        $assembled->toArray(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $originalTokens = app(ContextCostEstimator::class)
        ->measureValue($prompt)['estimated_tokens'];

    $capacityEvidence = static fn (int $capacityTokens): array => [
        'harness' => 'codex',
        'model' => 'test',
        'resolved_capacity_tokens' => $capacityTokens,
        'max_output_tokens' => (int) floor($capacityTokens * 0.2),
        'capacity_source' => 'test:model',
        'capacity_source_version' => 1,
        'fallback' => false,
    ];

    $betweenTargetAndWarning = app(ContextBudgetGuard::class)->evaluate(
        AgentRole::Coder,
        $prompt,
        $assembled,
        $capacityEvidence(
            (int) ceil($originalTokens / 0.72),
        ),
    );

    expect($betweenTargetAndWarning->blocked)
        ->toBeFalse()
        ->and(
            $betweenTargetAndWarning->evidence[
                'original_estimated_tokens'
            ],
        )
        ->toBeGreaterThan(
            $betweenTargetAndWarning->evidence[
                'budget_tokens'
            ],
        )
        ->and(
            $betweenTargetAndWarning->evidence[
                'original_estimated_tokens'
            ],
        )
        ->toBeLessThan(
            $betweenTargetAndWarning->evidence[
                'warning_tokens'
            ],
        )
        ->and(
            $betweenTargetAndWarning->evidence[
                'warning_reason'
            ],
        )
        ->toBeNull()
        ->and(
            $betweenTargetAndWarning->evidence[
                'decision'
            ],
        )
        ->toBe('approved')
        ->and(
            $betweenTargetAndWarning->evidence[
                'reductions'
            ],
        )
        ->toBe([])
        ->and($betweenTargetAndWarning->prompt)
        ->toBe($prompt);

    $atWarning = app(ContextBudgetGuard::class)->evaluate(
        AgentRole::Coder,
        $prompt,
        $assembled,
        $capacityEvidence(
            (int) ceil($originalTokens / 0.75),
        ),
    );

    expect($atWarning->blocked)
        ->toBeFalse()
        ->and(
            $atWarning->evidence[
                'original_estimated_tokens'
            ],
        )
        ->toBeGreaterThanOrEqual(
            $atWarning->evidence[
                'warning_tokens'
            ],
        )
        ->and(
            $atWarning->evidence[
                'original_estimated_tokens'
            ],
        )
        ->toBeLessThan(
            $atWarning->evidence[
                'hard_ceiling_tokens'
            ],
        )
        ->and(
            $atWarning->evidence[
                'warning_reason'
            ],
        )
        ->toBe(
            'estimated_context_at_or_above_warning_threshold',
        )
        ->and(
            $atWarning->evidence[
                'decision'
            ],
        )
        ->toBe('reduced')
        ->and(
            $atWarning->evidence[
                'reduction_reason'
            ],
        )
        ->toBe(
            'warning_threshold_reached_reduce_toward_normal_target',
        )
        ->and(
            $atWarning->evidence[
                'reduced_sources'
            ],
        )
        ->toContain('agent_default_context')
        ->and(
            $atWarning->evidence[
                'final_estimated_tokens'
            ],
        )
        ->toBeLessThan(
            $atWarning->evidence[
                'original_estimated_tokens'
            ],
        )
        ->and(
            $atWarning->evidence[
                'final_estimated_tokens'
            ],
        )
        ->toBeLessThan(
            $atWarning->evidence[
                'hard_ceiling_tokens'
            ],
        );
});

test('deterministic reduction preserves required task evidence and produces the same final hash', function () {
    $project = p3013Project('Deterministic reduction project');
    $agent = Agent::factory()->for($project)->create([
        'role' => AgentRole::Coder,
        'default_context' => str_repeat('agent guidance ', 18000),
    ]);
    $assembled = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Coder,
        [
            'task_key' => 'TASK-100',
            'objective' => 'Keep the required objective.',
            'acceptance_criteria' => ['Required acceptance criterion.'],
            'relevant_paths' => array_fill(0, 100, str_repeat('path-context ', 80)),
            'obsidian_project_knowledge' => [str_repeat('obsidian ', 5000)],
        ],
    );
    $prompt = "Coder contract.\n\n".json_encode(
        $assembled->toArray(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $capacity = [
        'harness' => 'codex',
        'model' => 'test',
        'resolved_capacity_tokens' => 50000,
        'max_output_tokens' => 10000,
        'capacity_source' => 'test:model',
        'capacity_source_version' => 1,
        'fallback' => false,
    ];

    $first = app(ContextBudgetGuard::class)->evaluate(
        AgentRole::Coder,
        $prompt,
        $assembled,
        $capacity,
    );
    $second = app(ContextBudgetGuard::class)->evaluate(
        AgentRole::Coder,
        $prompt,
        $assembled,
        $capacity,
    );

    expect($first->blocked)->toBeFalse()
        ->and($first->evidence['warning_reason'])->not->toBeNull()
        ->and($first->evidence['reduced_sources'])->toContain('agent_default_context')
        ->and($first->context?->taskContext['objective'])->toBe('Keep the required objective.')
        ->and($first->context?->taskContext['acceptance_criteria'])->toBe(['Required acceptance criterion.'])
        ->and($first->context?->hash)->toBe($second->context?->hash)
        ->and($first->prompt)->toBe($second->prompt)
        ->and($first->evidence)->toBe($second->evidence);
});

test('required context at the eighty percent hard ceiling blocks before provider execution', function () {
    $project = p3013Project('Required hard ceiling project');
    $agent = Agent::factory()->for($project)->create([
        'role' => AgentRole::Coder,
        'default_context' => null,
    ]);
    $assembled = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Coder,
        [
            'task_key' => 'TASK-101',
            'objective' => str_repeat('required-objective ', 14000),
            'acceptance_criteria' => ['Must remain present.'],
        ],
    );
    $prompt = "Coder contract.\n\n".json_encode(
        $assembled->toArray(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    $decision = app(ContextBudgetGuard::class)->evaluate(
        AgentRole::Coder,
        $prompt,
        $assembled,
        [
            'harness' => 'codex',
            'model' => 'test',
            'resolved_capacity_tokens' => 50000,
            'max_output_tokens' => 10000,
            'capacity_source' => 'test:model',
            'capacity_source_version' => 1,
            'fallback' => false,
        ],
    );

    expect($decision->blocked)->toBeTrue()
        ->and($decision->evidence['block_reason'])->toBe('required_context_reaches_or_exceeds_hard_ceiling')
        ->and($decision->evidence['required_estimated_tokens'])->toBeGreaterThanOrEqual($decision->evidence['hard_ceiling_tokens']);
});
