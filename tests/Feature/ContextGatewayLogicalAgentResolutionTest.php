<?php

use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\Contracts\Context\ContextPack;
use App\Exceptions\AgentResolutionFailed;
use App\Models\Agent;
use App\Models\Project;
use App\Services\AgentContextAssembler;
use App\Services\ContextGatewayAgentResolver;

function logicalAgentProject(string $name): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-logical-agent-'.fake()->uuid(),
    ]);
}

test('ContextGatewayLogicalAgentResolution resolves a registered, enabled Agent inside its Project scope', function () {
    $project = logicalAgentProject('Logical Agent resolution project');
    $agent = Agent::factory()->for($project)->create(['role' => AgentRole::Coder]);

    $identity = app(ContextGatewayAgentResolver::class)->resolve($project->id, $agent->id);

    expect($identity->agentId)->toBe($agent->id)
        ->and($identity->projectId)->toBe($project->id)
        ->and($identity->role)->toBe(AgentRole::Coder->value)
        ->and($identity->configurationVersion)->toBe($agent->configuration_version);
});

test('ContextGatewayLogicalAgentResolution stays stable across harness, model, and repeated resolution', function () {
    $project = logicalAgentProject('Logical Agent stability project');
    $agent = Agent::factory()->for($project)->create([
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => 'gpt-5.6-sol',
    ]);

    $resolver = app(ContextGatewayAgentResolver::class);
    $first = $resolver->resolve($project->id, $agent->id);

    $agent->update(['harness' => AgentHarnessIdentifier::ClaudeCode, 'model' => 'claude-sonnet-5']);
    $second = $resolver->resolve($project->id, $agent->id);

    expect($first->agentId)->toBe($second->agentId)
        ->and($first->projectId)->toBe($second->projectId)
        ->and($first->role)->toBe($second->role)
        ->and($first->toArray())->not->toHaveKeys(['harness', 'model', 'process_id', 'session_id']);
});

test('ContextGatewayLogicalAgentResolution fails closed for an unknown Agent ID', function () {
    $project = logicalAgentProject('Logical Agent unknown project');

    expect(fn () => app(ContextGatewayAgentResolver::class)->resolve($project->id, 999999))
        ->toThrow(AgentResolutionFailed::class);
});

test('ContextGatewayLogicalAgentResolution fails closed for a disabled Agent', function () {
    $project = logicalAgentProject('Logical Agent disabled project');
    $agent = Agent::factory()->for($project)->create(['enabled' => false]);

    expect(fn () => app(ContextGatewayAgentResolver::class)->resolve($project->id, $agent->id))
        ->toThrow(AgentResolutionFailed::class);
});

test('ContextGatewayLogicalAgentResolution fails closed for an Agent registered under a different Project', function () {
    $project = logicalAgentProject('Logical Agent scoped project');
    $otherProject = logicalAgentProject('Logical Agent other project');
    $agent = Agent::factory()->for($otherProject)->create();

    expect(fn () => app(ContextGatewayAgentResolver::class)->resolve($project->id, $agent->id))
        ->toThrow(AgentResolutionFailed::class);
});

test('ContextGatewayLogicalAgentResolution fails closed for a global system Agent', function () {
    $project = logicalAgentProject('Logical Agent global project');
    $globalAgent = Agent::query()->whereNull('project_id')->where('role', AgentRole::RecoveryEngineer)->firstOrFail();

    expect(fn () => app(ContextGatewayAgentResolver::class)->resolve($project->id, $globalAgent->id))
        ->toThrow(AgentResolutionFailed::class);
});

test('ContextGatewayLogicalAgentResolution grants no AgentWorker or workflow-transition authority', function () {
    $project = logicalAgentProject('Logical Agent authority project');
    $agent = Agent::factory()->for($project)->create(['role' => AgentRole::Coder]);

    $identity = app(ContextGatewayAgentResolver::class)->resolve($project->id, $agent->id);
    $payload = $identity->toArray();

    expect($payload)->not->toHaveKeys(['lease_id', 'worker_instance_id', 'status', 'slot'])
        ->and(json_encode($payload))->not->toContain('AgentWorker');
});

test('ContextGatewayLogicalAgentResolution produces evidence compatible with the shared ContextRequest/ContextPack contract', function () {
    $project = logicalAgentProject('Logical Agent contract project');
    $agent = Agent::factory()->for($project)->create(['role' => AgentRole::Coder]);
    $taskContext = ['objective' => 'Verify logical Agent identity feeds the shared context contract.'];

    $identity = app(ContextGatewayAgentResolver::class)->resolve($project->id, $agent->id);
    $request = $identity->toContextRequest($taskContext);
    $assembled = app(AgentContextAssembler::class)->assemble($agent, AgentRole::Coder, $taskContext);
    $pack = ContextPack::fromAssembledContext($request, $assembled);

    expect($request->projectId)->toBe($identity->projectId)
        ->and($request->agentId)->toBe($identity->agentId)
        ->and($pack->projectId)->toBe($identity->projectId)
        ->and($pack->agentId)->toBe($identity->agentId);
});
