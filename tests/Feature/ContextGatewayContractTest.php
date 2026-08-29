<?php

use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\Contracts\Context\ContextAuthority;
use App\Contracts\Context\ContextBudgetResult;
use App\Contracts\Context\ContextPack;
use App\Contracts\Context\ContextRequest;
use App\Models\Agent;
use App\Models\Project;
use App\Services\AgentContextAssembler;
use App\Services\AgentHarnessResolver;
use App\Services\ContextBudgetGuard;

function contextGatewayProject(string $name): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-context-gateway-'.fake()->uuid(),
    ]);
}

test('ContextGatewayContract ContextPack distinguishes required from reducible sources with provenance', function () {
    $project = contextGatewayProject('Context gateway pack project');
    $agent = Agent::factory()->for($project)->create([
        'harness' => AgentHarnessIdentifier::Codex,
        'default_context' => 'Prefer small, focused diffs.',
    ]);

    $taskContext = [
        'objective' => 'Introduce provider-independent context contracts.',
        'acceptance_criteria' => ['Contracts remain provider-independent.'],
        'previous_attempt' => ['summary' => 'First attempt evidence.'],
        'review_findings' => [['severity' => 'major', 'location' => 'app/Contracts']],
    ];

    $assembled = app(AgentContextAssembler::class)->assemble($agent, AgentRole::Coder, $taskContext);

    $request = new ContextRequest(
        projectId: $project->id,
        agentId: $agent->id,
        taskContext: $taskContext,
    );

    $pack = ContextPack::fromAssembledContext($request, $assembled);
    $payload = $pack->toArray();

    expect($pack->projectId)->toBe($project->id)
        ->and($pack->agentId)->toBe($agent->id)
        ->and($payload)->not->toHaveKey('role')
        ->and($pack->contextSchemaVersion)->toBe($assembled->contextSchemaVersion)
        ->and($pack->hash)->toBe($assembled->hash)
        ->and($payload['sources'])->not->toBeEmpty();

    $requiredKeys = array_map(fn ($source) => $source->key, $pack->requiredSources());
    $reducibleKeys = array_map(fn ($source) => $source->key, $pack->reducibleSources());

    expect($requiredKeys)->toContain('system_rules', 'task_core', 'retry_recovery_evidence', 'review_evidence')
        ->and($reducibleKeys)->toContain('agent_default_context', 'skills', 'obsidian_context')
        ->and(array_intersect($requiredKeys, $reducibleKeys))->toBe([]);

    foreach ($pack->sources as $source) {
        expect($source->toArray())->toHaveKeys(['source', 'scope', 'reason', 'estimated_tokens', 'authority'])
            ->and($source->estimatedTokens)->toBeGreaterThanOrEqual(0)
            ->and($source->authority)->toBeInstanceOf(ContextAuthority::class);
    }
});

test('ContextGatewayContract ContextPack construction is deterministic for identical inputs', function () {
    $project = contextGatewayProject('Context gateway determinism project');
    $agent = Agent::factory()->for($project)->create([
        'default_context' => 'Prefer small, focused diffs.',
    ]);
    $taskContext = ['objective' => 'Deterministic construction proof.'];

    $request = new ContextRequest($project->id, $agent->id, $taskContext);

    $first = ContextPack::fromAssembledContext(
        $request,
        app(AgentContextAssembler::class)->assemble($agent, AgentRole::Coder, $taskContext),
    );
    $second = ContextPack::fromAssembledContext(
        $request,
        app(AgentContextAssembler::class)->assemble($agent, AgentRole::Coder, $taskContext),
    );

    expect($first->toArray())->toBe($second->toArray());
});

test('ContextGatewayContract ContextBudgetResult wraps the existing deterministic Context Budget evidence unchanged', function () {
    $project = contextGatewayProject('Context gateway budget project');
    $agent = Agent::factory()->for($project)->create([
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => 'gpt-5.6-sol',
        'default_context' => 'Prefer small, focused diffs.',
    ]);

    $harness = app(AgentHarnessResolver::class)->resolve($agent);
    $capacityEvidence = [
        ...$harness->capabilities()->resolveContextCapacity($agent, $harness->identifier()),
        'harness' => $harness->identifier()->value,
        'model' => $agent->model,
    ];

    $taskContext = ['objective' => 'Verify Context Budget contract wrapping.'];
    $context = app(AgentContextAssembler::class)->assemble($agent, AgentRole::Coder, $taskContext);
    $prompt = "AIOS assembled context:\n".json_encode(
        $context->toArray(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    $decision = app(ContextBudgetGuard::class)->evaluate(AgentRole::Coder, $prompt, $context, $capacityEvidence);
    $result = ContextBudgetResult::fromDecision($decision);

    expect($result->blocked)->toBe($decision->blocked)
        ->and($result->decision)->toBe($decision->evidence['decision'])
        ->and($result->capacitySource)->toBe($decision->evidence['capacity_source'] ?? null)
        ->and($result->capacitySourceVersion)->toBe($decision->evidence['capacity_source_version'] ?? null)
        ->and($result->capacityTokens)->toBe($decision->evidence['resolved_capacity_tokens'])
        ->and($result->targetTokens)->toBe($decision->evidence['budget_tokens'])
        ->and($result->warningTokens)->toBe($decision->evidence['warning_tokens'])
        ->and($result->hardCeilingTokens)->toBe($decision->evidence['hard_ceiling_tokens'])
        ->and($result->requiredEstimatedTokens)->toBe($decision->evidence['required_estimated_tokens'])
        ->and($result->originalEstimatedTokens)->toBe($decision->evidence['original_estimated_tokens'])
        ->and($result->finalEstimatedTokens)->toBe($decision->evidence['final_estimated_tokens'])
        ->and($result->sourceContributions)->toBe($decision->evidence['source_contributions'])
        ->and($result->includedSources)->toBe($decision->evidence['included_sources'])
        ->and($result->reducedSources)->toBe($decision->evidence['reduced_sources'])
        ->and($result->excludedSources)->toBe($decision->evidence['excluded_sources'])
        ->and($result->reductions)->toBe($decision->evidence['reductions'])
        ->and($result->reductionMethod)->toBe($decision->evidence['reduction_method'])
        ->and($result->reductionReason)->toBe($decision->evidence['reduction_reason'] ?? null)
        ->and($result->originalContextHash)->toBe($decision->evidence['original_context_hash'])
        ->and($result->finalContextHash)->toBe($decision->evidence['final_context_hash'])
        ->and($result->toArray()['decision'])->toBe($decision->evidence['decision']);
});

test('ContextGatewayContract contracts carry no Codex, Claude Code, or workflow-role coupling', function () {
    $contextPackFile = file_get_contents(app_path('Contracts/Context/ContextPack.php'));
    $contextRequestFile = file_get_contents(app_path('Contracts/Context/ContextRequest.php'));
    $contextSourceFile = file_get_contents(app_path('Contracts/Context/ContextSource.php'));
    $contextBudgetResultFile = file_get_contents(app_path('Contracts/Context/ContextBudgetResult.php'));

    foreach ([$contextPackFile, $contextRequestFile, $contextSourceFile, $contextBudgetResultFile] as $source) {
        expect($source)
            ->not->toContain('CodexHarness')
            ->not->toContain('ClaudeCodeHarness')
            ->not->toContain('AgentWorker')
            ->not->toContain('WorkflowRole')
            ->not->toContain('AgentRole');
    }
});
