<?php

use App\AgentHarness;
use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentWorker;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function createAgentPersistenceProject(string $name): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-agent-test-'.Str::uuid(),
    ]);
}

test('project agents persist independently from agent worker runtime state', function () {
    $agent = Agent::factory()->create();

    expect($agent->project)->toBeInstanceOf(Project::class)
        ->and($agent->role)->toBe(AgentRole::Coder)
        ->and($agent->harness)->toBe(AgentHarness::Codex)
        ->and($agent->enabled)->toBeTrue()
        ->and($agent->configuration_version)->toBe(1)
        ->and($agent->project->agents()->whereKey($agent->id)->exists())->toBeTrue()
        ->and(AgentWorker::query()->where('project_id', $agent->project_id)->exists())->toBeFalse()
        ->and(Schema::hasColumn('agents', 'agent_worker_id'))->toBeFalse()
        ->and(Schema::hasColumn('agents', 'settings'))->toBeFalse()
        ->and(Schema::hasColumn('agents', 'api_key'))->toBeFalse()
        ->and(Schema::hasColumn('agents', 'token'))->toBeFalse();
});

test('project workflow roles cannot be created as global agents', function () {
    expect(fn () => Agent::query()->create([
        'name' => 'Global Coder',
        'role' => AgentRole::Coder,
        'harness' => AgentHarness::Codex,
        'enabled' => true,
    ]))->toThrow(
        LogicException::class,
        'A global Agent role must be a supported AIOS system role.',
    );
});

test('agent names are unique only within their owning project', function () {
    $firstProject = createAgentPersistenceProject('First agent project');
    $secondProject = createAgentPersistenceProject('Second agent project');

    Agent::factory()->for($firstProject)->create(['name' => 'Primary Coder']);
    Agent::factory()->for($secondProject)->create(['name' => 'Primary Coder']);

    expect(fn () => Agent::factory()->for($firstProject)->create(['name' => 'Primary Coder']))
        ->toThrow(QueryException::class);
});

test('agent roles and harnesses are bounded to supported workflow configuration', function () {
    $project = createAgentPersistenceProject('Bounded agent configuration');

    $agent = Agent::factory()->for($project)->create([
        'role' => AgentRole::Reviewer,
        'harness' => AgentHarness::ClaudeCode,
    ]);

    expect($agent->role)->toBe(AgentRole::Reviewer)
        ->and($agent->harness)->toBe(AgentHarness::ClaudeCode)
        ->and($agent->getRawOriginal('role'))->toBe(AgentRole::Reviewer->value)
        ->and($agent->getRawOriginal('harness'))->toBe(AgentHarness::ClaudeCode->value);

    expect(fn () => Agent::factory()->for($project)->create(['role' => 'unsupported_role']))
        ->toThrow(ValueError::class)
        ->and(fn () => Agent::factory()->for($project)->create(['harness' => 'unsupported_harness']))
        ->toThrow(ValueError::class)
        ->and(fn () => Agent::factory()->for($project)->create(['role' => AgentRole::KnowledgeArchitect]))
        ->toThrow(LogicException::class, 'Agent role must be a supported AIOS workflow role.');
});

test('agent configuration changes increment version and callers cannot override it', function () {
    $project = createAgentPersistenceProject('Versioned agent configuration');
    $agent = Agent::factory()->for($project)->create(['configuration_version' => 99]);

    expect($agent->configuration_version)->toBe(1);

    $agent->update(['default_context' => 'Use only task-relevant project context.']);

    expect($agent->refresh()->configuration_version)->toBe(2);

    $agent->configuration_version = 99;
    $agent->save();

    expect($agent->refresh()->configuration_version)->toBe(2);

    $agent->save();

    expect($agent->refresh()->configuration_version)->toBe(2);

    $agent->touch();

    expect($agent->refresh()->configuration_version)->toBe(2);

    $agent->configuration_version = 500;
    $agent->update([
        'harness' => AgentHarness::ClaudeCode,
        'reasoning_setting' => 'high',
    ]);

    expect($agent->refresh()->configuration_version)->toBe(3)
        ->and($agent->harness)->toBe(AgentHarness::ClaudeCode)
        ->and($agent->reasoning_setting)->toBe('high');
});

test('stale agent instances advance from the latest persisted configuration version', function () {
    $project = createAgentPersistenceProject('Stale versioned agent configuration');
    $agent = Agent::factory()->for($project)->create();

    $firstInstance = Agent::query()->findOrFail($agent->id);
    $secondInstance = Agent::query()->findOrFail($agent->id);

    expect($firstInstance->configuration_version)->toBe(1)
        ->and($secondInstance->configuration_version)->toBe(1);

    $firstInstance->update([
        'default_context' => 'First independently loaded Agent edit.',
    ]);

    expect($firstInstance->refresh()->configuration_version)->toBe(2);

    $secondInstance->update([
        'reasoning_setting' => 'high',
    ]);

    $persistedAgent = Agent::query()->findOrFail($agent->id);

    expect($persistedAgent->configuration_version)->toBe(3)
        ->and($persistedAgent->default_context)->toBe('First independently loaded Agent edit.')
        ->and($persistedAgent->reasoning_setting)->toBe('high');
});

test('agent project ownership cannot be moved after persistence', function () {
    $project = createAgentPersistenceProject('Original agent project');
    $otherProject = createAgentPersistenceProject('Other agent project');
    $agent = Agent::factory()->for($project)->create();

    $agent->project()->associate($otherProject);

    expect(fn () => $agent->save())
        ->toThrow(LogicException::class, 'Agent project ownership cannot be changed.')
        ->and($agent->fresh()->project_id)->toBe($project->id);
});

test('agent configuration rejects high confidence secret material', function (string $secret) {
    $project = createAgentPersistenceProject('Secret-safe agent configuration');

    expect(fn () => Agent::factory()->for($project)->create(['default_context' => $secret]))
        ->toThrow(LogicException::class, 'Agent configuration cannot contain secret material.');
})->with([
    'provider token' => 'sk-'.str_repeat('a', 24),
    'bearer credential' => 'Authorization: Bearer provider-secret-token',
    'environment credential' => 'API_KEY=super-secret-value',
    'lowercase environment credential' => 'provider_token=super-secret-value',
    'private key' => "-----BEGIN PRIVATE KEY-----\nsecret-material",
    'json access token assignment' => '{"access_token": "provider-token-value-123456"}',
    'json api key assignment' => '{"api_key": "provider-api-key-value-123456"}',
    'yaml access token assignment' => 'access_token: provider-token-value-123456',
    'yaml api key assignment' => 'api_key: provider-api-key-value-123456',
    'credential-bearing url' => 'postgresql://aios:database-password-123@db.example.test/aios',
]);

test('agent context may describe security rules without being treated as a secret', function () {
    $project = createAgentPersistenceProject('Security guidance agent configuration');

    $agent = Agent::factory()->for($project)->create([
        'default_context' => 'Security rule: never expose API keys or access tokens. Credentials and .env contents must stay outside AIOS configuration.',
    ]);

    expect($agent->exists)->toBeTrue();
});
