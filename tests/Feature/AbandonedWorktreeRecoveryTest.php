<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\StaleWorkerRecovery;
use App\Services\TaskWorktreeManager;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Create a real Git-backed project so an isolated Task worktree can actually be acquired.
 *
 * @return array{0: Project, 1: string}
 */
function abandonedWorktreeProject(): array
{
    $workspaceRoot = sys_get_temp_dir();
    config()->set('aios.workspace_root', $workspaceRoot);

    $path = $workspaceRoot.'/aios-abandoned-worktree-project-'.fake()->uuid();
    File::ensureDirectoryExists($path);

    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);
    File::put($path.'/baseline.txt', "baseline\n");
    Process::path($path)->run(['git', 'add', 'baseline.txt']);
    Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);

    $baseSha = trim(Process::path($path)->run(['git', 'rev-parse', 'HEAD'])->output());

    $project = Project::create([
        'name' => 'Abandoned Worktree '.fake()->uuid(),
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
        'git_head_sha' => $baseSha,
    ]);

    return [$project, $baseSha];
}

function abandonedWorktreeTask(Project $project, TaskStatus $status): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-'.fake()->uuid(),
        'position' => 1,
        'title' => 'Abandoned worktree recovery task',
        'objective' => 'Recover abandoned worktrees safely.',
        'acceptance_criteria' => ['The abandoned worktree is removed.'],
        'implementation_prompt' => 'Recover the task.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

test('an expired worker lease releases the abandoned Coder attempt worktree idempotently', function () {
    [$project, $baseSha] = abandonedWorktreeProject();
    $worker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'status' => 'working', 'worker_instance_id' => fake()->uuid(), 'lease_id' => fake()->uuid(), 'lease_expires_at' => now()->subMinute(), 'last_heartbeat_at' => now()->subMinutes(5)]);
    $task = abandonedWorktreeTask($project, TaskStatus::Coding);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'base_sha' => $baseSha, 'status' => 'running', 'started_at' => now()->subMinutes(5)]);
    AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'agent_worker_id' => $worker->id, 'worker_lease_id' => $worker->lease_id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'expired-lease'), 'started_at' => now()->subMinutes(5)]);

    $worktreePath = app(TaskWorktreeManager::class)->acquire($task, $attempt);
    expect(is_dir($worktreePath))->toBeTrue();

    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(1)
        ->and($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($attempt->refresh()->status)->toBe('interrupted')
        ->and(is_dir($worktreePath))->toBeFalse();

    // A repeated recovery sweep over the already-recovered task must remain a safe no-op.
    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(0)
        ->and(is_dir($worktreePath))->toBeFalse();
});

test('an orphaned Coder run releases its abandoned attempt worktree', function () {
    [$project, $baseSha] = abandonedWorktreeProject();
    $worker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'status' => 'idle', 'worker_instance_id' => fake()->uuid(), 'lease_id' => null, 'lease_expires_at' => null, 'last_heartbeat_at' => now()]);
    $task = abandonedWorktreeTask($project, TaskStatus::Coding);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'base_sha' => $baseSha, 'status' => 'running', 'started_at' => now()->subMinutes(5)]);
    AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'agent_worker_id' => $worker->id, 'worker_lease_id' => fake()->uuid(), 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'orphaned'), 'started_at' => now()->subMinutes(5)]);

    $worktreePath = app(TaskWorktreeManager::class)->acquire($task, $attempt);
    expect(is_dir($worktreePath))->toBeTrue();

    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(1)
        ->and($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($attempt->refresh()->status)->toBe('interrupted')
        ->and(is_dir($worktreePath))->toBeFalse();
});

test('an abandoned Coder finalization releases its worktree without any running AgentRun', function () {
    [$project, $baseSha] = abandonedWorktreeProject();
    $worker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'status' => 'idle', 'lease_id' => null, 'lease_expires_at' => null, 'last_heartbeat_at' => now()]);
    $task = abandonedWorktreeTask($project, TaskStatus::Coding);
    Task::query()->whereKey($task->id)->update(['updated_at' => now()->subMinutes(5)]);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'base_sha' => $baseSha, 'status' => 'running', 'started_at' => now()->subMinutes(5)]);
    AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'agent_worker_id' => $worker->id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Completed, 'exit_code' => 0, 'prompt_hash' => hash('sha256', 'abandoned-finalization'), 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]);

    $worktreePath = app(TaskWorktreeManager::class)->acquire($task, $attempt);
    expect(is_dir($worktreePath))->toBeTrue();

    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(1)
        ->and($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($attempt->refresh()->status)->toBe('interrupted')
        ->and(is_dir($worktreePath))->toBeFalse();
});
