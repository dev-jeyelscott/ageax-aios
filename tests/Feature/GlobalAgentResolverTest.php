<?php

use App\AgentRole;
use App\Models\Agent;
use App\Services\AgentHarnessResolver;
use App\Services\GlobalAgentResolver;
use Illuminate\Database\QueryException;

/**
 * Resolve a specific persisted global Agent without relying on database row ordering.
 */
function p5001ResolverAgent(AgentRole $role): Agent
{
    return Agent::query()
        ->whereNull('project_id')
        ->where('role', $role)
        ->sole();
}

test('the resolver returns every explicitly activated global system Agent', function () {
    foreach ([
        AgentRole::RecoveryEngineer,
        AgentRole::Orchestrator,
        AgentRole::KnowledgeArchitect,
    ] as $role) {
        $agent = p5001ResolverAgent($role);

        $resolved = app(
            GlobalAgentResolver::class,
        )->forRole($role);

        expect($resolved->id)->toBe($agent->id)
            ->and($resolved->project_id)->toBeNull()
            ->and($resolved->role)->toBe($role)
            ->and($resolved->enabled)->toBeTrue();
    }
});

test('a disabled Orchestrator is rejected', function () {
    $agent = p5001ResolverAgent(
        AgentRole::Orchestrator,
    );

    $agent->update(['enabled' => false]);

    expect(
        fn () => app(
            GlobalAgentResolver::class,
        )->forRole(AgentRole::Orchestrator),
    )->toThrow(LogicException::class);
});

test('a missing Orchestrator is rejected without fallback', function () {
    p5001ResolverAgent(
        AgentRole::Orchestrator,
    )->delete();

    expect(
        fn () => app(
            GlobalAgentResolver::class,
        )->forRole(AgentRole::Orchestrator),
    )->toThrow(LogicException::class);
});

test('a disabled Knowledge Architect is rejected distinctly', function () {
    $agent = p5001ResolverAgent(
        AgentRole::KnowledgeArchitect,
    );

    $agent->update(['enabled' => false]);

    expect(
        fn () => app(
            GlobalAgentResolver::class,
        )->forRole(
            AgentRole::KnowledgeArchitect,
        ),
    )->toThrow(
        LogicException::class,
        'The global Agent configured for the [knowledge_architect] system role is disabled.',
    );
});

test('a missing Knowledge Architect is rejected distinctly without fallback', function () {
    p5001ResolverAgent(
        AgentRole::KnowledgeArchitect,
    )->delete();

    expect(
        fn () => app(
            GlobalAgentResolver::class,
        )->forRole(
            AgentRole::KnowledgeArchitect,
        ),
    )->toThrow(
        LogicException::class,
        'The global Agent configured for the [knowledge_architect] system role is missing.',
    );
});

test('a misconfigured Orchestrator is rejected by the existing harness capability boundary', function () {
    $agent = p5001ResolverAgent(
        AgentRole::Orchestrator,
    );

    $agent->update([
        'model' => 'future-unapproved-orchestrator-model',
        'reasoning_setting' => null,
    ]);

    $resolved = app(
        GlobalAgentResolver::class,
    )->forRole(AgentRole::Orchestrator);

    expect(
        fn () => app(
            AgentHarnessResolver::class,
        )->resolve($resolved),
    )->toThrow(LogicException::class);
});

test('a misconfigured Knowledge Architect is rejected by the existing harness capability boundary', function () {
    $agent = p5001ResolverAgent(
        AgentRole::KnowledgeArchitect,
    );

    $agent->update([
        'model' => 'future-unapproved-knowledge-model',
        'reasoning_setting' => null,
    ]);

    $resolved = app(
        GlobalAgentResolver::class,
    )->forRole(
        AgentRole::KnowledgeArchitect,
    );

    expect(
        fn () => app(
            AgentHarnessResolver::class,
        )->resolve($resolved),
    )->toThrow(LogicException::class);
});

test('the database prevents a second global Orchestrator singleton', function () {
    $agent = p5001ResolverAgent(
        AgentRole::Orchestrator,
    );

    expect(fn () => Agent::query()->create([
        'name' => 'Duplicate Global Orchestrator',
        'role' => AgentRole::Orchestrator,
        'harness' => $agent->harness,
        'model' => $agent->model,
        'reasoning_setting' => $agent->reasoning_setting,
        'enabled' => true,
    ]))->toThrow(QueryException::class);
});

test('the database prevents a second global Knowledge Architect singleton', function () {
    $agent = p5001ResolverAgent(
        AgentRole::KnowledgeArchitect,
    );

    expect(fn () => Agent::query()->create([
        'name' => 'Duplicate Global Knowledge Architect',
        'role' => AgentRole::KnowledgeArchitect,
        'harness' => $agent->harness,
        'model' => $agent->model,
        'reasoning_setting' => $agent->reasoning_setting,
        'enabled' => true,
    ]))->toThrow(QueryException::class);
});
