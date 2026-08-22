<?php

use App\AgentRole;
use App\Models\Agent;
use App\Services\AgentHarnessResolver;
use App\Services\GlobalAgentResolver;
use Illuminate\Database\QueryException;

/**
 * Resolve exactly one seeded global Agent for the requested system role.
 */
function p5001ResolverAgent(AgentRole $role): Agent
{
    return Agent::query()
        ->whereNull('project_id')
        ->where('role', $role)
        ->sole();
}

test('it resolves each approved singleton global system Agent by role', function () {
    foreach ([AgentRole::RecoveryEngineer, AgentRole::Orchestrator] as $role) {
        $agent = p5001ResolverAgent($role);
        $resolved = app(GlobalAgentResolver::class)->forRole($role);

        expect($resolved->id)->toBe($agent->id)
            ->and($resolved->project_id)->toBeNull()
            ->and($resolved->role)->toBe($role)
            ->and($resolved->enabled)->toBeTrue();
    }
});

test('it refuses to resolve a disabled global Orchestrator', function () {
    $agent = p5001ResolverAgent(AgentRole::Orchestrator);
    $agent->update(['enabled' => false]);

    app(GlobalAgentResolver::class)->forRole(AgentRole::Orchestrator);
})->throws(LogicException::class);

test('it refuses to resolve a missing global Orchestrator without falling back', function () {
    p5001ResolverAgent(AgentRole::Orchestrator)->delete();

    app(GlobalAgentResolver::class)->forRole(AgentRole::Orchestrator);
})->throws(LogicException::class);

test('enum membership alone does not make Knowledge Architect a resolvable global Agent', function () {
    app(GlobalAgentResolver::class)->forRole(AgentRole::KnowledgeArchitect);
})->throws(LogicException::class);

test('a misconfigured Orchestrator is rejected by the existing harness boundary', function () {
    $agent = p5001ResolverAgent(AgentRole::Orchestrator);

    $agent->update([
        'model' => 'future-unapproved-orchestrator-model',
    ]);

    $resolved = app(GlobalAgentResolver::class)
        ->forRole(AgentRole::Orchestrator);

    expect(fn () => app(AgentHarnessResolver::class)->resolve($resolved))
        ->toThrow(LogicException::class);
});

test('the database prevents a second global Orchestrator singleton', function () {
    $agent = p5001ResolverAgent(AgentRole::Orchestrator);

    expect(fn () => Agent::query()->create([
        'name' => 'Duplicate Global Orchestrator',
        'role' => AgentRole::Orchestrator,
        'harness' => $agent->getRawOriginal('harness'),
        'model' => $agent->model,
        'reasoning_setting' => $agent->reasoning_setting,
        'default_context' => null,
        'enabled' => true,
    ]))->toThrow(QueryException::class);
});
