<?php

use App\Actions\ClaimTask;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\CodexCliRunner;
use App\Services\StaleWorkerRecovery;
use App\Services\WorkerHeartbeat;
use App\TaskStatus;
use Illuminate\Support\Facades\Process;

function leasedWorker(Project $project, AgentRole $role = AgentRole::Coder): AgentWorker
{
    return AgentWorker::create(['project_id' => $project->id, 'role' => $role, 'status' => 'idle']);
}

function leasedTask(Project $project, string $key = 'TASK-001', int $position = 1, TaskStatus $status = TaskStatus::Queued): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => $position,
        'title' => 'Lease recovery task',
        'objective' => 'Recover safely.',
        'acceptance_criteria' => ['The task is recovered once.'],
        'implementation_prompt' => 'Recover the task.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

test('a quiet Codex execution renews its heartbeat without stdout', function () {
    config()->set('aios.worker_heartbeat_interval_seconds', 1);
    $project = Project::create(['name' => 'Quiet', 'path' => '/tmp/quiet-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $worker = leasedWorker($project);
    $heartbeat = app(WorkerHeartbeat::class);
    $lease = $heartbeat->acquire($project, AgentRole::Coder, fake()->uuid());
    $worker->update(['lease_expires_at' => now()->addSecond()]);
    Process::fake(['*' => Process::describe()->iterations(2)]);

    app(CodexCliRunner::class)->runAtPath(base_path(), 'Quiet work.', null, function () use ($heartbeat, $lease): void {
        $heartbeat->renew($lease);
    });

    expect($worker->refresh()->last_heartbeat_at)->not->toBeNull()
        ->and($worker->lease_expires_at->isFuture())->toBeTrue();
});

test('a valid worker lease prevents stale heartbeat recovery', function () {
    $project = Project::create(['name' => 'Lease', 'path' => '/tmp/lease-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $worker = leasedWorker($project);
    $lease = app(WorkerHeartbeat::class)->acquire($project, AgentRole::Coder, fake()->uuid());
    $worker->update(['last_heartbeat_at' => now()->subHours(2)]);
    $task = leasedTask($project, status: TaskStatus::Coding);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'running', 'started_at' => now()->subHours(2)]);
    $run = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'agent_worker_id' => $worker->id, 'worker_instance_id' => $lease->workerInstanceId, 'worker_lease_id' => $lease->leaseId, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'quiet'), 'started_at' => now()->subHours(2)]);

    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(0)
        ->and($task->refresh()->status)->toBe(TaskStatus::Coding)
        ->and($attempt->refresh()->status)->toBe('running')
        ->and($run->refresh()->status)->toBe(AgentRunStatus::Running);
});

test('an expired genuinely dead worker is taken over and its same task becomes retryable', function () {
    $project = Project::create(['name' => 'Dead', 'path' => '/tmp/dead-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $worker = leasedWorker($project);
    $worker->update(['status' => 'working', 'worker_instance_id' => fake()->uuid(), 'lease_id' => fake()->uuid(), 'lease_expires_at' => now()->subMinute(), 'last_heartbeat_at' => now()->subMinutes(5)]);
    $task = leasedTask($project, status: TaskStatus::Coding);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'running', 'started_at' => now()->subMinutes(5)]);
    $run = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'agent_worker_id' => $worker->id, 'worker_lease_id' => $worker->lease_id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'dead'), 'started_at' => now()->subMinutes(5)]);

    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(1)
        ->and($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($attempt->refresh()->status)->toBe('interrupted')
        ->and($run->refresh()->status)->toBe(AgentRunStatus::Interrupted)
        ->and($worker->refresh()->status)->toBe('interrupted')
        ->and($project->auditEvents()->where('event_type', 'worker.lease_taken_over')->exists())->toBeTrue()
        ->and($project->auditEvents()->where('event_type', 'worker.recovered')->exists())->toBeTrue();
});

test('a task orphaned by a crashed execution is recovered even once the worker looks idle again', function () {
    $project = Project::create(['name' => 'Orphaned', 'path' => '/tmp/orphaned-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $worker = leasedWorker($project);
    $deadLeaseId = fake()->uuid();
    $task = leasedTask($project, status: TaskStatus::Coding);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'running', 'started_at' => now()->subMinutes(5)]);
    $run = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'agent_worker_id' => $worker->id, 'worker_lease_id' => $deadLeaseId, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'orphaned'), 'started_at' => now()->subMinutes(5)]);

    // Once the dead lease expired, a fresh worker process reclaimed-and-released the same role's
    // slot on an ordinary idle cycle, leaving the AgentWorker row healthy (fresh heartbeat, no
    // lease) even though the run above never finished.
    $worker->update(['status' => 'idle', 'worker_instance_id' => fake()->uuid(), 'lease_id' => null, 'lease_expires_at' => null, 'last_heartbeat_at' => now()]);

    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(1)
        ->and($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($attempt->refresh()->status)->toBe('interrupted')
        ->and($run->refresh()->status)->toBe(AgentRunStatus::Interrupted)
        ->and($worker->refresh()->status)->toBe('idle')
        ->and($project->auditEvents()->where('event_type', 'task.recovered')->where('payload->reason', 'orphaned_agent_run')->exists())->toBeTrue();
});

test('a task whose harness finished but was never finalized is recovered even with no running AgentRun', function () {
    // Regression: if the host process is killed between AgentRunRecorder::complete() (harness
    // finished, AgentRun already marked completed) and RunCoderTask's subsequent validate/commit/
    // transition step, the task is left claimed (Coding) with no AgentRun still Running at all.
    // recoverOrphanedRuns() only matches a Running AgentRun, so it never sees this; without
    // recoverAbandonedFinalizations() the task would block the Coder role (and, if it's the
    // current phase, the Reviewer role too) forever.
    $project = Project::create(['name' => 'Abandoned', 'path' => '/tmp/abandoned-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $worker = leasedWorker($project);
    $worker->update(['status' => 'idle', 'lease_id' => null, 'lease_expires_at' => null, 'last_heartbeat_at' => now()]);
    $task = leasedTask($project, status: TaskStatus::Coding);
    Task::query()->whereKey($task->id)->update(['updated_at' => now()->subMinutes(5)]);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'running', 'started_at' => now()->subMinutes(5)]);
    $run = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'agent_worker_id' => $worker->id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Completed, 'exit_code' => 0, 'prompt_hash' => hash('sha256', 'abandoned'), 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]);

    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(1)
        ->and($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($attempt->refresh()->status)->toBe('interrupted')
        ->and($run->refresh()->status)->toBe(AgentRunStatus::Completed)
        ->and($project->auditEvents()->where('event_type', 'task.recovered')->where('payload->reason', 'abandoned_finalization')->exists())->toBeTrue();
});

test('a task still genuinely being coded is left untouched by abandoned-finalization recovery', function () {
    $project = Project::create(['name' => 'Active', 'path' => '/tmp/active-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $worker = leasedWorker($project);
    $lease = app(WorkerHeartbeat::class)->acquire($project, AgentRole::Coder, fake()->uuid());
    $task = leasedTask($project, status: TaskStatus::Coding);
    Task::query()->whereKey($task->id)->update(['updated_at' => now()->subMinutes(5)]);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'running', 'started_at' => now()->subMinutes(5)]);
    $run = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'agent_worker_id' => $worker->id, 'worker_instance_id' => $lease->workerInstanceId, 'worker_lease_id' => $lease->leaseId, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'active'), 'started_at' => now()->subMinutes(5)]);

    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(0)
        ->and($task->refresh()->status)->toBe(TaskStatus::Coding)
        ->and($attempt->refresh()->status)->toBe('running')
        ->and($run->refresh()->status)->toBe(AgentRunStatus::Running);
});

test('a completed run with an active matching lease is left for its worker to finalize', function () {
    $project = Project::create(['name' => 'Finalizing', 'path' => '/tmp/finalizing-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $worker = leasedWorker($project);
    $lease = app(WorkerHeartbeat::class)->acquire($project, AgentRole::Coder, fake()->uuid());
    $task = leasedTask($project, status: TaskStatus::Coding);
    Task::query()->whereKey($task->id)->update(['updated_at' => now()->subMinutes(5)]);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'running', 'started_at' => now()->subMinutes(5)]);
    $run = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'agent_worker_id' => $worker->id, 'worker_instance_id' => $lease->workerInstanceId, 'worker_lease_id' => $lease->leaseId, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Completed, 'exit_code' => 0, 'prompt_hash' => hash('sha256', 'finalizing'), 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]);

    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(0)
        ->and($task->refresh()->status)->toBe(TaskStatus::Coding)
        ->and($attempt->refresh()->status)->toBe('running')
        ->and($run->refresh()->status)->toBe(AgentRunStatus::Completed);
});

test('a completed reviewer run from an earlier attempt does not recover a fresh reviewer claim', function () {
    $project = Project::create(['name' => 'Fresh review', 'path' => '/tmp/fresh-review-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $worker = leasedWorker($project, AgentRole::Reviewer);
    $worker->update(['status' => 'idle', 'lease_id' => null, 'lease_expires_at' => null, 'last_heartbeat_at' => now()]);
    $task = leasedTask($project, status: TaskStatus::Reviewing);
    Task::query()->whereKey($task->id)->update(['updated_at' => now()->subMinutes(5)]);
    $previousAttempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'completed', 'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9)]);
    $currentAttempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 2, 'status' => 'completed', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]);
    AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'task_attempt_id' => $previousAttempt->id, 'agent_worker_id' => $worker->id, 'role' => AgentRole::Reviewer, 'status' => AgentRunStatus::Completed, 'exit_code' => 0, 'prompt_hash' => hash('sha256', 'previous-review'), 'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9)]);

    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(0)
        ->and($task->refresh()->status)->toBe(TaskStatus::Reviewing)
        ->and($currentAttempt->refresh()->status)->toBe('completed');
});

test('two worker processes cannot acquire the same role lease or task execution', function () {
    $project = Project::create(['name' => 'Concurrent', 'path' => '/tmp/concurrent-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    leasedWorker($project);
    $task = leasedTask($project);
    $heartbeat = app(WorkerHeartbeat::class);

    $firstLease = $heartbeat->acquire($project, AgentRole::Coder, fake()->uuid());
    $secondLease = $heartbeat->acquire($project, AgentRole::Coder, fake()->uuid());

    expect($firstLease)->not->toBeNull()
        ->and($secondLease)->toBeNull()
        ->and(app(ClaimTask::class)->handle($project, AgentRole::Coder)?->id)->toBe($task->id)
        ->and(app(ClaimTask::class)->handle($project, AgentRole::Coder))->toBeNull();
});

test('only one recovery owner can take over an expired lease', function () {
    $project = Project::create(['name' => 'Recovery', 'path' => '/tmp/recovery-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $worker = leasedWorker($project);
    $worker->update(['worker_instance_id' => fake()->uuid(), 'lease_id' => fake()->uuid(), 'lease_expires_at' => now()->subSecond(), 'last_heartbeat_at' => now()->subMinutes(5)]);
    $heartbeat = app(WorkerHeartbeat::class);

    $firstRecovery = $heartbeat->takeoverExpired($project, AgentRole::Coder, fake()->uuid(), 60);
    $secondRecovery = $heartbeat->takeoverExpired($project, AgentRole::Coder, fake()->uuid(), 60);

    expect($firstRecovery)->not->toBeNull()
        ->and($secondRecovery)->toBeNull()
        ->and($worker->refresh()->lease_id)->toBe($firstRecovery->leaseId);
});

test('recovery resumes the same task before another queued task can be claimed', function () {
    $project = Project::create(['name' => 'Resume', 'path' => '/tmp/resume-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $worker = leasedWorker($project);
    $worker->update(['worker_instance_id' => fake()->uuid(), 'lease_id' => fake()->uuid(), 'lease_expires_at' => now()->subMinute(), 'last_heartbeat_at' => now()->subMinutes(5)]);
    $firstTask = leasedTask($project, 'TASK-001', 1, TaskStatus::Coding);
    $secondTask = leasedTask($project, 'TASK-002', 2);
    TaskAttempt::create(['task_id' => $firstTask->id, 'number' => 1, 'status' => 'running', 'started_at' => now()->subMinutes(5)]);

    app(StaleWorkerRecovery::class)->recover($project, 60);
    $claimed = app(ClaimTask::class)->handle($project, AgentRole::Coder);

    expect($claimed?->id)->toBe($firstTask->id)
        ->and($secondTask->refresh()->status)->toBe(TaskStatus::Queued);
});
