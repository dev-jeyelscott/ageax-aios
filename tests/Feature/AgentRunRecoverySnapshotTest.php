<?php

use App\Actions\RunCoderTask;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\StaleWorkerRecovery;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function recoverySnapshotProject(string $name): Project
{
    $project = Project::create(['name' => $name, 'path' => '/tmp/aios-recovery-snapshot-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    File::ensureDirectoryExists($project->path);

    return $project;
}

function recoverySnapshotTask(Project $project, TaskStatus $status = TaskStatus::Coding): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Recovery snapshot task',
        'objective' => 'Prove recovery preserves the persisted snapshot.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

test('recovering an interrupted run preserves its persisted configuration snapshot untouched', function () {
    $project = recoverySnapshotProject('Recovery preserves snapshot project');
    $agent = Agent::factory()->for($project)->create(['role' => AgentRole::Coder]);
    AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'agent_id' => $agent->id, 'status' => 'working', 'last_heartbeat_at' => now()->subMinutes(5)]);
    $task = recoverySnapshotTask($project);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'running', 'started_at' => now()->subMinutes(5)]);
    $run = AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'agent_id' => $agent->id,
        'role' => AgentRole::Coder,
        'harness' => 'codex',
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', 'interrupted'),
        'configuration_snapshot' => ['agent' => ['id' => $agent->id, 'configuration_version' => $agent->configuration_version], 'skills' => [], 'context_hash' => 'original-hash'],
        'context_schema_version' => 1,
        'started_at' => now()->subMinutes(5),
    ]);

    app(StaleWorkerRecovery::class)->recover($project, 60);

    expect($run->refresh()->status)->toBe(AgentRunStatus::Interrupted)
        ->and($run->configuration_snapshot)->toBe(['agent' => ['id' => $agent->id, 'configuration_version' => $agent->configuration_version], 'skills' => [], 'context_hash' => 'original-hash'])
        ->and($run->agent_id)->toBe($agent->id)
        ->and($run->context_schema_version)->toBe(1);
});

test('a fresh retry captures a new snapshot reflecting the current agent configuration, not the interrupted one', function () {
    $project = recoverySnapshotProject('Fresh retry snapshot project');
    $agent = Agent::factory()->for($project)->create(['role' => AgentRole::Coder, 'model' => null]);
    AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'agent_id' => $agent->id, 'status' => 'working', 'last_heartbeat_at' => now()->subMinutes(5)]);
    $task = recoverySnapshotTask($project);
    $interruptedAttempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'running', 'started_at' => now()->subMinutes(5)]);
    $interruptedRun = AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'agent_id' => $agent->id,
        'role' => AgentRole::Coder,
        'harness' => 'codex',
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', 'interrupted'),
        'configuration_snapshot' => ['agent' => ['id' => $agent->id, 'configuration_version' => $agent->configuration_version, 'model' => null], 'skills' => [], 'context_hash' => 'interrupted-hash'],
        'context_schema_version' => 1,
        'started_at' => now()->subMinutes(5),
    ]);

    app(StaleWorkerRecovery::class)->recover($project, 60);
    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($interruptedRun->refresh()->status)->toBe(AgentRunStatus::Interrupted);

    // AIOS now resolves the then-current Agent configuration for the fresh retry attempt.
    $agent->update(['model' => 'gpt-5.6-sol']);
    $task->update(['status' => TaskStatus::Coding]);
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'stopped')]);

    app(RunCoderTask::class)->handle($task->refresh());

    $retryRun = AgentRun::query()->whereBelongsTo($task)->where('id', '!=', $interruptedRun->id)->sole();

    expect($retryRun->configuration_snapshot['agent']['model'])->toBe('gpt-5.6-sol')
        ->and($retryRun->configuration_snapshot['context_hash'])->not->toBe('interrupted-hash')
        ->and($interruptedRun->refresh()->configuration_snapshot['agent']['model'])->toBeNull()
        ->and($interruptedRun->configuration_snapshot['context_hash'])->toBe('interrupted-hash');
});
