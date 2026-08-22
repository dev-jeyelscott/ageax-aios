<?php

use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Services\AgentContextAssembler;
use App\Services\AgentHarnessResolver;
use App\Services\AgentRunRecorder;
use App\Services\ContextBudgetGuard;

/**
 * Resolve the single persisted global Orchestrator used by infrastructure tests.
 */
function p5001InfrastructureAgent(): Agent
{
    return Agent::query()
        ->whereNull('project_id')
        ->where('role', AgentRole::Orchestrator)
        ->sole();
}

test('the Orchestrator supports Codex and Claude Code through the existing harness resolver', function () {
    $agent = p5001InfrastructureAgent();
    $resolver = app(AgentHarnessResolver::class);

    $agent->update([
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => null,
        'reasoning_setting' => null,
    ]);

    expect($resolver->resolve($agent)->identifier())
        ->toBe(AgentHarnessIdentifier::Codex);

    $agent->update([
        'harness' => AgentHarnessIdentifier::ClaudeCode,
        'model' => null,
        'reasoning_setting' => null,
    ]);

    expect($resolver->resolve($agent)->identifier())
        ->toBe(AgentHarnessIdentifier::ClaudeCode);
});

test('the Orchestrator uses the existing conservative Context Budget evidence model', function () {
    $agent = p5001InfrastructureAgent();

    $agent->update([
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => null,
        'reasoning_setting' => null,
    ]);

    $harness = app(AgentHarnessResolver::class)->resolve($agent);
    $capacityEvidence = [
        ...$harness->capabilities()->resolveContextCapacity(
            $agent,
            $harness->identifier(),
        ),
        'harness' => $harness->identifier()->value,
        'model' => $agent->model,
    ];

    $context = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Orchestrator,
        [
            'objective' => 'Evaluate durable AIOS evidence and provide advisory guidance only.',
            'acceptance_criteria' => [
                'Do not mutate workflow, Agents, workers, Git, or project state.',
            ],
        ],
    );

    $prompt = "Orchestrator advisory contract.\n\n".json_encode(
        $context->toArray(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    $decision = app(ContextBudgetGuard::class)->evaluate(
        AgentRole::Orchestrator,
        $prompt,
        $context,
        $capacityEvidence,
    );

    expect($decision->blocked)->toBeFalse()
        ->and($decision->evidence['role'])->toBe(AgentRole::Orchestrator->value)
        ->and($decision->evidence['harness'])->toBe(AgentHarnessIdentifier::Codex->value)
        ->and($decision->evidence['role_target_percent'])->toBe(70)
        ->and($decision->evidence['warning_percent'])->toBe(75)
        ->and($decision->evidence['hard_ceiling_percent'])->toBe(80)
        ->and($decision->evidence['reserved_percent'])->toBe(20)
        ->and($decision->evidence['decision'])->toBe('approved');
});

test('an Orchestrator run uses the existing immutable AgentRun evidence path', function () {
    $agent = p5001InfrastructureAgent();

    $agent->update([
        'harness' => AgentHarnessIdentifier::ClaudeCode,
        'model' => null,
        'reasoning_setting' => null,
        'default_context' => 'Provide advisory architecture analysis only.',
    ]);

    $project = Project::factory()->create();

    $context = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Orchestrator,
        [
            'objective' => 'Evaluate bounded durable evidence.',
            'acceptance_criteria' => [
                'Return advisory evidence only.',
            ],
        ],
    );

    $prompt = 'Evaluate bounded durable evidence without mutating AIOS state.';

    $run = app(AgentRunRecorder::class)->start(
        $project,
        AgentRole::Orchestrator,
        $prompt,
        agent: $agent,
        context: $context,
    );

    $snapshot = $run->configuration_snapshot;

    expect($run->agent_id)->toBe($agent->id)
        ->and($run->agent_worker_id)->toBeNull()
        ->and($run->role)->toBe(AgentRole::Orchestrator)
        ->and($run->harness)->toBe(AgentHarnessIdentifier::ClaudeCode->value)
        ->and(data_get($snapshot, 'agent.id'))->toBe($agent->id)
        ->and(data_get($snapshot, 'agent.role'))->toBe(AgentRole::Orchestrator->value)
        ->and(data_get($snapshot, 'agent.harness'))->toBe(AgentHarnessIdentifier::ClaudeCode->value)
        ->and(data_get($snapshot, 'agent.model'))->toBeNull()
        ->and(data_get($snapshot, 'agent.reasoning_setting'))->toBeNull()
        ->and(data_get($snapshot, 'agent.configuration_version'))->toBe($agent->configuration_version)
        ->and(data_get($snapshot, 'context_hash'))->toBe($context->hash)
        ->and($run->context_schema_version)->toBe($context->contextSchemaVersion)
        ->and($run->context_cost_schema_version)->toBe($context->contextCostSchemaVersion)
        ->and($run->context_cost_estimate)->toEqual($context->contextCostEstimate)
        ->and(AgentWorker::query()->where('agent_id', $agent->id)->exists())->toBeFalse();

    $agent->update([
        'default_context' => 'Changed after the recorded run.',
    ]);

    expect($run->fresh()->configuration_snapshot)->toEqual($snapshot);
});
