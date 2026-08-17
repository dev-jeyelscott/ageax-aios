<?php

use App\AgentRole;
use App\Models\Agent;
use App\Services\GlobalAgentResolver;

test('it resolves the enabled global agent seeded for a system role', function () {
    $agent = Agent::query()->whereNull('project_id')->where('role', AgentRole::RecoveryEngineer)->sole();

    $resolved = app(GlobalAgentResolver::class)->forRole(AgentRole::RecoveryEngineer);

    expect($resolved->id)->toBe($agent->id);
});

test('it refuses to resolve a disabled global agent', function () {
    Agent::query()->whereNull('project_id')->where('role', AgentRole::RecoveryEngineer)->update(['enabled' => false]);

    app(GlobalAgentResolver::class)->forRole(AgentRole::RecoveryEngineer);
})->throws(LogicException::class);

test('it refuses to resolve a role with no seeded global agent', function () {
    Agent::query()->whereNull('project_id')->delete();

    app(GlobalAgentResolver::class)->forRole(AgentRole::RecoveryEngineer);
})->throws(LogicException::class);
