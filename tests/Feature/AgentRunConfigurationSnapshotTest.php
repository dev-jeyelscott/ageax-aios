<?php

use App\Actions\AssignSkillToAgent;
use App\Actions\RunCoderTask;
use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Skill;
use App\Models\Task;
use App\ProjectStatus;
use App\Services\AgentContextAssembler;
use App\Services\AgentExecutionProfile;
use App\Services\CodexHarness;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function snapshotProject(string $name): Project
{
    $project = Project::create(['name' => $name, 'path' => '/tmp/aios-snapshot-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    File::ensureDirectoryExists($project->path);

    return $project;
}

function snapshotTask(Project $project): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Snapshot task',
        'objective' => 'Prove the snapshot is captured.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::Coding,
    ]);
}

test('a run bound to an agent persists an immutable configuration snapshot', function () {
    $project = snapshotProject('Snapshot capture project');
    $agent = Agent::factory()->for($project)->create(['role' => AgentRole::Coder, 'name' => 'Coder One']);
    $skill = Skill::factory()->for($project)->create(['name' => 'Style Skill', 'slug' => 'style-skill', 'applicable_roles' => []]);
    app(AssignSkillToAgent::class)->handle($agent, $skill);
    AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'agent_id' => $agent->id, 'status' => 'idle']);
    $task = snapshotTask($project);
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'stopped')]);

    app(RunCoderTask::class)->handle($task);

    $run = AgentRun::query()->whereBelongsTo($task)->sole();

    expect($run->isLegacyRun())->toBeFalse()
        ->and($run->agent_id)->toBe($agent->id)
        ->and($run->harness)->toBe('codex')
        ->and($run->context_schema_version)->toBe(AgentContextAssembler::ContextSchemaVersion)
        ->and($run->configuration_snapshot['context_schema_version'])->toBe(AgentContextAssembler::ContextSchemaVersion)
        ->and($run->configuration_snapshot['agent']['id'])->toBe($agent->id)
        ->and($run->configuration_snapshot['agent']['configuration_version'])->toBe($agent->configuration_version)
        ->and(collect($run->configuration_snapshot['skills'])->pluck('slug')->all())->toBe(['style-skill'])
        ->and($run->configuration_snapshot['skills'][0]['version'])->toBe($skill->version)
        ->and($run->configuration_snapshot)->toHaveKey('context_hash')
        ->and($run->configuration_snapshot['execution_profile']['schema_version'])->toBe(1)
        ->and($run->configuration_snapshot['execution_profile']['context']['hash'])->toBe($run->configuration_snapshot['context_hash'])
        ->and($run->configuration_snapshot['execution_profile']['model']['agent_id'])->toBe($agent->id)
        ->and($run->configuration_snapshot['execution_profile']['model']['harness'])->toBe('codex')
        ->and($run->configuration_snapshot['execution_profile']['prompt']['hash'])->toBe($run->prompt_hash)
        ->and($run->configuration_snapshot['execution_profile']['prompt']['raw_prompt_persisted'])->toBeFalse()
        ->and($run->configuration_snapshot['execution_profile']['tools']['capabilities_resolved'])->toBeFalse()
        ->and($run->configuration_snapshot['execution_profile']['tools']['workspace_boundary'])->toBe('aios_owned_project_scoped');
});

test('editing the agent or skill afterward never changes an already persisted run snapshot', function () {
    $project = snapshotProject('Snapshot immutability project');
    $agent = Agent::factory()->for($project)->create(['role' => AgentRole::Coder, 'model' => null]);
    AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'agent_id' => $agent->id, 'status' => 'idle']);
    $task = snapshotTask($project);
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'stopped')]);

    app(RunCoderTask::class)->handle($task);
    $run = AgentRun::query()->whereBelongsTo($task)->sole();
    $originalSnapshot = $run->configuration_snapshot;
    $originalConfigurationVersion = $agent->configuration_version;

    $agent->update(['model' => 'gpt-5.6-sol', 'reasoning_setting' => 'high']);

    expect($agent->refresh()->configuration_version)->toBeGreaterThan($originalConfigurationVersion)
        ->and($run->refresh()->configuration_snapshot)->toBe($originalSnapshot)
        ->and($run->configuration_snapshot['agent']['configuration_version'])->toBe($originalConfigurationVersion)
        ->and($run->configuration_snapshot['agent']['model'])->toBeNull();
});

test('execution profile evidence redacts sensitive execution settings', function () {
    $project = snapshotProject('Profile redaction project');
    $agent = Agent::factory()->for($project)->create(['role' => AgentRole::Coder]);
    $context = app(AgentContextAssembler::class)
        ->assemble($agent, AgentRole::Coder, ['task_key' => 'TASK-001'])
        ->withExecutionSettings(['max_execution_seconds' => 60, 'api_token' => 'never-persist-this']);

    $profile = app(AgentExecutionProfile::class)->resolve(
        $agent,
        AgentRole::Coder,
        $context,
        'Implement the bounded task.',
        app(CodexHarness::class),
    );

    expect($profile['tools']['execution_settings'])->toBe([
        'max_execution_seconds' => 60,
        'api_token' => '[REDACTED]',
    ])
        ->and($profile['tools']['capabilities_resolved'])->toBeTrue()
        ->and(json_encode($profile, JSON_THROW_ON_ERROR))->not->toContain('never-persist-this');
});

test('a run for an unbound legacy project remains a legacy run without a snapshot', function () {
    $project = snapshotProject('Legacy run project');
    $task = snapshotTask($project);
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'stopped')]);

    app(RunCoderTask::class)->handle($task);

    $run = AgentRun::query()->whereBelongsTo($task)->sole();

    expect($run->isLegacyRun())->toBeTrue()
        ->and($run->agent_id)->toBeNull()
        ->and($run->configuration_snapshot)->toBeNull();
});

test('audit events identify the agent and harness used for the run', function () {
    $project = snapshotProject('Audit identification project');
    $agent = Agent::factory()->for($project)->create(['role' => AgentRole::Coder]);
    AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'agent_id' => $agent->id, 'status' => 'idle']);
    $task = snapshotTask($project);
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'stopped')]);

    app(RunCoderTask::class)->handle($task);

    $startedPayload = $task->auditEvents()->where('event_type', 'agent.execution_started')->firstOrFail()->payload;
    $completedPayload = $task->auditEvents()->where('event_type', 'agent.execution_completed')->firstOrFail()->payload;

    expect($startedPayload['agent_id'])->toBe($agent->id)
        ->and($startedPayload['harness'])->toBe('codex')
        ->and($completedPayload['agent_id'])->toBe($agent->id)
        ->and($completedPayload['harness'])->toBe('codex');
});
