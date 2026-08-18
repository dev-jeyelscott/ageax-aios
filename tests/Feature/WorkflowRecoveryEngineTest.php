<?php

use App\Actions\ClaimTask;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\RecoveryIncidentStatus;
use App\Services\RecoveryEngineerRunner;
use App\Services\RecoveryRepositoryLifecycle;
use App\Services\RecoveryWorktreeManager;
use App\Services\WorkflowRecoveryEngine;
use App\Services\WorkflowRecoveryScanner;
use App\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;

function recoveryProject(string $name = 'Example'): Project
{
    return Project::create(['name' => $name, 'path' => '/tmp/recovery-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
}

/**
 * The Pest `mock()` helper does not reliably rebind into the container this application's test
 * environment resolves through; binding directly via app()->instance() does.
 *
 * @template TClass of object
 *
 * @param  class-string<TClass>  $class
 * @return TClass&MockInterface
 */
function recoveryMock(string $class): MockInterface
{
    $mock = Mockery::mock($class);
    app()->instance($class, $mock);

    return $mock;
}

/**
 * By default, diagnosis runs against a real (empty) disposable directory standing in for the
 * worktree, so realpath()-based safety checks in WorkflowRecoveryEngine's copy step behave exactly
 * as they would for a genuine `git worktree add` result. Tests exercising a successful AIOS fix
 * commit override this with a worktree that actually contains the expected changed file.
 */
function recoveryWorktreeMock(): MockInterface
{
    $mock = recoveryMock(RecoveryWorktreeManager::class);
    $mock->shouldReceive('create')->andReturnUsing(function (): string {
        $path = sys_get_temp_dir().'/aios-recovery-worktree-test-'.fake()->uuid();
        mkdir($path, 0700, true);

        return $path;
    });
    $mock->shouldReceive('destroy')->andReturnUsing(function (string $repositoryPath, string $worktreePath): void {
        if (is_dir($worktreePath)) {
            File::deleteDirectory($worktreePath);
        }
    });

    return $mock;
}

function recoveryTask(Project $project, string $key, int $position, TaskStatus $status, ?CarbonImmutable $updatedAt = null): Task
{
    $task = Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => $position,
        'title' => 'Recovery task',
        'objective' => 'Recover safely.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => $status,
    ]);

    if ($updatedAt !== null) {
        $task->timestamps = false;
        $task->forceFill(['updated_at' => $updatedAt])->save();
    }

    return $task;
}

test('the workflow recovery scan is scheduled every five minutes', function () {
    $schedule = new Schedule;
    $schedule->command('aios:recover-workflows')->everyFiveMinutes();
    expect($schedule->events()[0]->expression)->toBe('*/5 * * * *');

    $registration = file_get_contents(base_path('bootstrap/app.php'));
    expect($registration)->toContain("command('aios:recover-workflows')->everyFiveMinutes()");
});

test('a task stuck blocked past the anti-flake floor is detected as an open incident', function () {
    config()->set('aios.recovery_stale_status_after_seconds', 60);
    $project = recoveryProject();
    $task = recoveryTask($project, 'TASK-001', 1, TaskStatus::Blocked, now()->subMinutes(5));

    app(WorkflowRecoveryScanner::class)->scan($project);

    $incident = RecoveryIncident::query()->where('task_id', $task->id)->first();
    expect($incident)->not->toBeNull()
        ->and($incident->status)->toBe(RecoveryIncidentStatus::Detected)
        ->and($incident->failure_type)->toBe('task_blocked')
        ->and($project->auditEvents()->where('event_type', 'recovery.incident_detected')->exists())->toBeTrue();
});

test('a task that only just became blocked is not yet flagged', function () {
    config()->set('aios.recovery_stale_status_after_seconds', 300);
    $project = recoveryProject();
    recoveryTask($project, 'TASK-001', 1, TaskStatus::Blocked, now());

    app(WorkflowRecoveryScanner::class)->scan($project);

    expect(RecoveryIncident::query()->count())->toBe(0);
});

test('a healthy actively-claimed task is never flagged regardless of elapsed time', function () {
    config()->set('aios.recovery_stale_status_after_seconds', 1);
    $project = recoveryProject();
    recoveryTask($project, 'TASK-001', 1, TaskStatus::Coding, now()->subHours(2));

    app(WorkflowRecoveryScanner::class)->scan($project);

    expect(RecoveryIncident::query()->count())->toBe(0);
});

test('repeated scans do not create duplicate open incidents for the same stuck task', function () {
    config()->set('aios.recovery_stale_status_after_seconds', 60);
    $project = recoveryProject();
    recoveryTask($project, 'TASK-001', 1, TaskStatus::Blocked, now()->subMinutes(5));
    $scanner = app(WorkflowRecoveryScanner::class);

    $scanner->scan($project);
    $scanner->scan($project);

    expect(RecoveryIncident::query()->count())->toBe(1);
});

test('an expired worker lease is auto-recovered by the scan into a single resolved incident', function () {
    $project = recoveryProject();
    $worker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'status' => 'idle']);
    $worker->update(['status' => 'working', 'worker_instance_id' => fake()->uuid(), 'lease_id' => fake()->uuid(), 'lease_expires_at' => now()->subMinute(), 'last_heartbeat_at' => now()->subMinutes(5)]);
    $task = recoveryTask($project, 'TASK-001', 1, TaskStatus::Coding);
    TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'running', 'started_at' => now()->subMinutes(5)]);
    AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'agent_worker_id' => $worker->id, 'worker_lease_id' => $worker->lease_id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'dead'), 'started_at' => now()->subMinutes(5)]);
    config()->set('aios.stale_worker_after_seconds', 60);

    app(WorkflowRecoveryScanner::class)->scan($project);

    $incident = RecoveryIncident::query()->where('project_id', $project->id)->first();
    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($incident)->not->toBeNull()
        ->and($incident->status)->toBe(RecoveryIncidentStatus::Recovered)
        ->and($incident->root_cause_category)->toBe('stale_lease')
        ->and($incident->task_id)->toBeNull();
});

test('a deterministically classified unsafe repository state escalates without invoking the recovery engineer', function () {
    $project = recoveryProject();
    $task = recoveryTask($project, 'TASK-001', 1, TaskStatus::Blocked);
    $project->auditEvents()->create(['task_id' => $task->id, 'event_type' => 'task.blocked_dirty_repository', 'payload' => [], 'occurred_at' => now()]);
    $incident = RecoveryIncident::create(['project_id' => $project->id, 'task_id' => $task->id, 'failure_type' => 'task_blocked', 'status' => RecoveryIncidentStatus::Detected, 'detected_at' => now()]);
    recoveryMock(RecoveryEngineerRunner::class)->shouldNotReceive('run');

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)->toBe(RecoveryIncidentStatus::Escalated)
        ->and($processed->root_cause_category)->toBe('unsafe_git_state')
        ->and($processed->recoverable)->toBeFalse()
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($project->auditEvents()->where('event_type', 'recovery.escalated')->exists())->toBeTrue();
});

test('a disabled global Recovery Engineer agent blocks diagnosis cleanly instead of attempting a run', function () {
    Agent::query()->whereNull('project_id')->where('role', AgentRole::RecoveryEngineer)->update(['enabled' => false]);
    $project = recoveryProject();
    $task = recoveryTask($project, 'TASK-001', 1, TaskStatus::Blocked);
    $incident = RecoveryIncident::create(['project_id' => $project->id, 'task_id' => $task->id, 'failure_type' => 'task_blocked', 'status' => RecoveryIncidentStatus::Detected, 'detected_at' => now()]);
    recoveryMock(RecoveryEngineerRunner::class)->shouldNotReceive('run');

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)->toBe(RecoveryIncidentStatus::Escalated)
        ->and($processed->root_cause_category)->toBe('configuration_environment')
        ->and($processed->recoverable)->toBeFalse()
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($project->auditEvents()->where('event_type', 'recovery.blocked_agent_misconfigured')->exists())->toBeTrue();
});

test('a diagnosed managed-project defect is requeued without editing the AIOS repository', function () {
    $project = recoveryProject();
    $task = recoveryTask($project, 'TASK-001', 1, TaskStatus::Blocked);
    $incident = RecoveryIncident::create(['project_id' => $project->id, 'task_id' => $task->id, 'failure_type' => 'task_blocked', 'status' => RecoveryIncidentStatus::Detected, 'detected_at' => now()]);
    recoveryMock(RecoveryRepositoryLifecycle::class)->shouldReceive('preflight')->once()->andReturn(['clean' => true, 'head_sha' => 'base-sha-1', 'errors' => []]);
    recoveryWorktreeMock();
    recoveryMock(RecoveryEngineerRunner::class)->shouldReceive('run')->once()->andReturn([
        'execution' => ['exit_code' => 0, 'output' => '{}', 'error_output' => ''],
        'decision' => [
            'root_cause_category' => 'managed_project_defect',
            'root_cause_summary' => 'The managed project task implementation itself is incorrect; this is not an AIOS defect.',
            'recoverable' => true,
            'fix_applied' => false,
            'changed_files' => [],
        ],
    ]);

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)->toBe(RecoveryIncidentStatus::Recovered)
        ->and($processed->root_cause_category)->toBe('managed_project_defect')
        ->and($processed->resulting_task_transition)->toBe('queued')
        ->and($task->refresh()->status)->toBe(TaskStatus::Queued)
        ->and(AgentRun::query()->where('recovery_incident_id', $incident->id)->where('role', AgentRole::RecoveryEngineer)->exists())->toBeTrue()
        ->and($project->auditEvents()->where('event_type', 'recovery.recovered')->exists())->toBeTrue();
});

test('a validated AIOS code fix is committed before the task is requeued', function () {
    $project = recoveryProject();
    $task = recoveryTask($project, 'TASK-001', 1, TaskStatus::Blocked);
    $incident = RecoveryIncident::create(['project_id' => $project->id, 'task_id' => $task->id, 'failure_type' => 'task_blocked', 'status' => RecoveryIncidentStatus::Detected, 'detected_at' => now()]);
    recoveryMock(RecoveryRepositoryLifecycle::class)
        ->shouldReceive('preflight')->once()->andReturn(['clean' => true, 'head_sha' => 'base-sha-1', 'errors' => []])
        ->shouldReceive('validate')->once()->with('/aios', ['app/Services/Example.php'])->andReturn(['passed' => true, 'checks' => [], 'evidence' => []])
        ->shouldReceive('commit')->once()->andReturn('commit-sha-1');
    config()->set('aios.recovery_repository_path', '/aios');
    recoveryWorktreeMock();
    recoveryMock(RecoveryEngineerRunner::class)->shouldReceive('run')->once()->andReturn([
        'execution' => ['exit_code' => 0, 'output' => '{}', 'error_output' => ''],
        'decision' => [
            'root_cause_category' => 'orchestration_defect',
            'root_cause_summary' => 'A bug in the orchestration logic caused the task to block spuriously.',
            'recoverable' => true,
            'fix_applied' => true,
            'changed_files' => ['app/Services/Example.php'],
            'fix_summary' => 'Fixed the spurious blocking condition.',
        ],
    ]);

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)->toBe(RecoveryIncidentStatus::Recovered)
        ->and($processed->commit_sha)->toBe('commit-sha-1')
        ->and($processed->base_sha)->toBe('base-sha-1')
        ->and($processed->fix_summary)->toBe('Fixed the spurious blocking condition.')
        ->and($task->refresh()->status)->toBe(TaskStatus::Queued)
        ->and($project->auditEvents()->where('event_type', 'recovery.fix_committed')->exists())->toBeTrue();
});

test('a fix that fails AIOS-independent validation is escalated and the task is never requeued', function () {
    $project = recoveryProject();
    $task = recoveryTask($project, 'TASK-001', 1, TaskStatus::Blocked);
    $incident = RecoveryIncident::create(['project_id' => $project->id, 'task_id' => $task->id, 'failure_type' => 'task_blocked', 'status' => RecoveryIncidentStatus::Detected, 'detected_at' => now()]);
    recoveryMock(RecoveryRepositoryLifecycle::class)
        ->shouldReceive('preflight')->once()->andReturn(['clean' => true, 'head_sha' => 'base-sha-1', 'errors' => []])
        ->shouldReceive('validate')->once()->andReturn(['passed' => false, 'checks' => ['secret_scan' => false], 'evidence' => ['secret_scan' => 'a likely secret was detected']])
        ->shouldNotReceive('commit');
    recoveryWorktreeMock();
    recoveryMock(RecoveryEngineerRunner::class)->shouldReceive('run')->once()->andReturn([
        'execution' => ['exit_code' => 0, 'output' => '{}', 'error_output' => ''],
        'decision' => [
            'root_cause_category' => 'orchestration_defect',
            'root_cause_summary' => 'Diagnosed an orchestration defect.',
            'recoverable' => true,
            'fix_applied' => true,
            'changed_files' => ['app/Services/Example.php'],
            'fix_summary' => 'Attempted fix.',
        ],
    ]);

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)->toBe(RecoveryIncidentStatus::Escalated)
        ->and($processed->commit_sha)->toBeNull()
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked);
});

test('repeated recovery engineer execution failures are retried up to the bounded limit then escalated', function () {
    config()->set('aios.recovery_max_attempts', 2);
    $project = recoveryProject();
    $task = recoveryTask($project, 'TASK-001', 1, TaskStatus::Blocked);
    $incident = RecoveryIncident::create(['project_id' => $project->id, 'task_id' => $task->id, 'failure_type' => 'task_blocked', 'status' => RecoveryIncidentStatus::Detected, 'detected_at' => now()]);
    recoveryMock(RecoveryRepositoryLifecycle::class)->shouldReceive('preflight')->twice()->andReturn(['clean' => true, 'head_sha' => 'base-sha-1', 'errors' => []]);
    recoveryWorktreeMock();
    recoveryMock(RecoveryEngineerRunner::class)->shouldReceive('run')->twice()->andReturn([
        'execution' => ['exit_code' => 1, 'output' => '', 'error_output' => 'Claude Code process exited with code 1.'],
        'decision' => null,
    ]);

    $firstAttempt = app(WorkflowRecoveryEngine::class)->process($incident);
    expect($firstAttempt->status)->toBe(RecoveryIncidentStatus::Detected)
        ->and($firstAttempt->attempt_count)->toBe(1);

    $secondAttempt = app(WorkflowRecoveryEngine::class)->process($firstAttempt);
    expect($secondAttempt->status)->toBe(RecoveryIncidentStatus::Escalated)
        ->and($secondAttempt->attempt_count)->toBe(2)
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked);
});

test('only one recovery attempt can claim an already-processed incident', function () {
    $project = recoveryProject();
    $task = recoveryTask($project, 'TASK-001', 1, TaskStatus::Blocked);
    $project->auditEvents()->create(['task_id' => $task->id, 'event_type' => 'task.blocked_dirty_repository', 'payload' => [], 'occurred_at' => now()]);
    $incident = RecoveryIncident::create(['project_id' => $project->id, 'task_id' => $task->id, 'failure_type' => 'task_blocked', 'status' => RecoveryIncidentStatus::Detected, 'detected_at' => now()]);
    $engine = app(WorkflowRecoveryEngine::class);

    $engine->process($incident);
    $engine->process($incident);

    expect($project->auditEvents()->where('event_type', 'recovery.escalated')->count())->toBe(1);
});

test('recovering a later task cannot bypass an incomplete earlier task', function () {
    $project = recoveryProject();
    $firstTask = recoveryTask($project, 'TASK-001', 1, TaskStatus::Coding);
    $secondTask = recoveryTask($project, 'TASK-002', 2, TaskStatus::Blocked);
    $incident = RecoveryIncident::create(['project_id' => $project->id, 'task_id' => $secondTask->id, 'failure_type' => 'task_blocked', 'status' => RecoveryIncidentStatus::Detected, 'detected_at' => now()]);
    recoveryMock(RecoveryRepositoryLifecycle::class)->shouldReceive('preflight')->once()->andReturn(['clean' => true, 'head_sha' => 'base-sha-1', 'errors' => []]);
    recoveryWorktreeMock();
    recoveryMock(RecoveryEngineerRunner::class)->shouldReceive('run')->once()->andReturn([
        'execution' => ['exit_code' => 0, 'output' => '{}', 'error_output' => ''],
        'decision' => ['root_cause_category' => 'managed_project_defect', 'root_cause_summary' => 'Not an AIOS defect.', 'recoverable' => true, 'fix_applied' => false, 'changed_files' => []],
    ]);

    app(WorkflowRecoveryEngine::class)->process($incident);

    expect($secondTask->refresh()->status)->toBe(TaskStatus::Queued)
        ->and(app(ClaimTask::class)->handle($project, AgentRole::Coder))->toBeNull();
});

test('a diagnosing incident whose recovery engineer run died mid-execution is reclaimed for a fresh diagnosis', function () {
    config()->set('aios.recovery_claim_stale_after_seconds', 60);
    $project = recoveryProject();
    $task = recoveryTask($project, 'TASK-001', 1, TaskStatus::Blocked);
    $incident = RecoveryIncident::create([
        'project_id' => $project->id, 'task_id' => $task->id, 'failure_type' => 'task_blocked',
        'status' => RecoveryIncidentStatus::Diagnosing, 'detected_at' => now()->subMinutes(20),
        'claim_token' => (string) Str::uuid(), 'claimed_at' => now()->subMinutes(20),
    ]);
    $run = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'recovery_incident_id' => $incident->id, 'role' => AgentRole::RecoveryEngineer, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'stale'), 'started_at' => now()->subMinutes(20)]);

    app(WorkflowRecoveryEngine::class)->reclaimStaleClaims($project);

    expect($incident->refresh()->status)->toBe(RecoveryIncidentStatus::Detected)
        ->and($incident->claimed_at)->toBeNull()
        ->and($run->refresh()->status)->toBe(AgentRunStatus::Interrupted)
        ->and($project->auditEvents()->where('event_type', 'recovery.claim_reclaimed')->exists())->toBeTrue();
});

test('a recently claimed diagnosing incident is left untouched by the reclaim pass', function () {
    config()->set('aios.recovery_claim_stale_after_seconds', 900);
    $project = recoveryProject();
    $task = recoveryTask($project, 'TASK-001', 1, TaskStatus::Blocked);
    $incident = RecoveryIncident::create([
        'project_id' => $project->id, 'task_id' => $task->id, 'failure_type' => 'task_blocked',
        'status' => RecoveryIncidentStatus::Diagnosing, 'detected_at' => now()->subMinutes(2),
        'claim_token' => (string) Str::uuid(), 'claimed_at' => now()->subMinutes(2),
    ]);

    app(WorkflowRecoveryEngine::class)->reclaimStaleClaims($project);

    expect($incident->refresh()->status)->toBe(RecoveryIncidentStatus::Diagnosing);
});

test('resolved incidents are never touched by the reclaim pass regardless of age', function () {
    config()->set('aios.recovery_claim_stale_after_seconds', 1);
    $project = recoveryProject();
    $task = recoveryTask($project, 'TASK-001', 1, TaskStatus::Blocked);
    $incident = RecoveryIncident::create([
        'project_id' => $project->id, 'task_id' => $task->id, 'failure_type' => 'task_blocked',
        'status' => RecoveryIncidentStatus::Escalated, 'detected_at' => now()->subHours(2),
        'claim_token' => (string) Str::uuid(), 'claimed_at' => now()->subHours(2), 'resolved_at' => now()->subHours(2),
    ]);

    app(WorkflowRecoveryEngine::class)->reclaimStaleClaims($project);

    expect($incident->refresh()->status)->toBe(RecoveryIncidentStatus::Escalated);
});

test('a dirty tree attributable to another task auto-resumes that task instead of escalating', function () {
    $path = sys_get_temp_dir().'/aios-recovery-attribution-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);
    File::put($path.'/baseline.txt', 'baseline');
    Process::path($path)->run(['git', 'add', 'baseline.txt']);
    Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);
    $head = trim(Process::path($path)->run(['git', 'rev-parse', 'HEAD'])->output());
    $project = Project::create(['name' => 'Attribution', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'dirty']);

    $originTask = recoveryTask($project, 'TASK-095', 1, TaskStatus::Blocked);
    TaskAttempt::create(['task_id' => $originTask->id, 'number' => 3, 'base_sha' => $head, 'status' => 'failed', 'changed_files' => ['app/Recipe.php'], 'started_at' => now()]);
    $originTask->auditEvents()->create(['event_type' => 'task.coder_retry_exhausted', 'payload' => [], 'occurred_at' => now()]);
    File::ensureDirectoryExists($path.'/app');
    File::put($path.'/app/Recipe.php', 'stale work');

    $blockedTask = recoveryTask($project, 'TASK-096', 2, TaskStatus::Blocked);
    $blockedTask->auditEvents()->create(['event_type' => 'task.blocked_dirty_repository', 'payload' => [], 'occurred_at' => now()]);
    $incident = RecoveryIncident::create(['project_id' => $project->id, 'task_id' => $blockedTask->id, 'failure_type' => 'task_blocked', 'status' => RecoveryIncidentStatus::Detected, 'detected_at' => now()]);
    recoveryMock(RecoveryEngineerRunner::class)->shouldNotReceive('run');

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)->toBe(RecoveryIncidentStatus::Recovered)
        ->and($processed->root_cause_category)->toBe('stale_agent_attempt')
        ->and($originTask->refresh()->status)->toBe(TaskStatus::Queued)
        ->and($blockedTask->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($project->auditEvents()->where('event_type', 'task.stale_attempt_reclaimed')->exists())->toBeTrue();
});

test('a dirty tree with an unexplained extra file is escalated even when a partial match exists', function () {
    $path = sys_get_temp_dir().'/aios-recovery-attribution-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);
    File::put($path.'/baseline.txt', 'baseline');
    Process::path($path)->run(['git', 'add', 'baseline.txt']);
    Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);
    $head = trim(Process::path($path)->run(['git', 'rev-parse', 'HEAD'])->output());
    $project = Project::create(['name' => 'Attribution', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'dirty']);

    $originTask = recoveryTask($project, 'TASK-095', 1, TaskStatus::Blocked);
    TaskAttempt::create(['task_id' => $originTask->id, 'number' => 3, 'base_sha' => $head, 'status' => 'failed', 'changed_files' => ['app/Recipe.php'], 'started_at' => now()]);
    File::ensureDirectoryExists($path.'/app');
    File::put($path.'/app/Recipe.php', 'stale work');
    File::put($path.'/app/Unexplained.php', 'someone else touched this');

    $blockedTask = recoveryTask($project, 'TASK-096', 2, TaskStatus::Blocked);
    $blockedTask->auditEvents()->create(['event_type' => 'task.blocked_dirty_repository', 'payload' => [], 'occurred_at' => now()]);
    $incident = RecoveryIncident::create(['project_id' => $project->id, 'task_id' => $blockedTask->id, 'failure_type' => 'task_blocked', 'status' => RecoveryIncidentStatus::Detected, 'detected_at' => now()]);
    recoveryMock(RecoveryEngineerRunner::class)->shouldNotReceive('run');

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)->toBe(RecoveryIncidentStatus::Escalated)
        ->and($processed->root_cause_category)->toBe('unsafe_git_state')
        ->and($originTask->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($blockedTask->refresh()->status)->toBe(TaskStatus::Blocked);
});

test('recovery preserves the historical AgentRun and audit evidence from the original failed attempt', function () {
    $project = recoveryProject();
    $task = recoveryTask($project, 'TASK-001', 1, TaskStatus::Blocked);
    $originalAttempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'failed', 'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9)]);
    $originalRun = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Failed, 'prompt_hash' => hash('sha256', 'original'), 'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9)]);
    $incident = RecoveryIncident::create(['project_id' => $project->id, 'task_id' => $task->id, 'failure_type' => 'task_blocked', 'status' => RecoveryIncidentStatus::Detected, 'detected_at' => now()]);
    recoveryMock(RecoveryRepositoryLifecycle::class)->shouldReceive('preflight')->once()->andReturn(['clean' => true, 'head_sha' => 'base-sha-1', 'errors' => []]);
    recoveryWorktreeMock();
    recoveryMock(RecoveryEngineerRunner::class)->shouldReceive('run')->once()->andReturn([
        'execution' => ['exit_code' => 0, 'output' => '{}', 'error_output' => ''],
        'decision' => ['root_cause_category' => 'managed_project_defect', 'root_cause_summary' => 'Not an AIOS defect.', 'recoverable' => true, 'fix_applied' => false, 'changed_files' => []],
    ]);

    app(WorkflowRecoveryEngine::class)->process($incident);

    expect($originalAttempt->refresh()->status)->toBe('failed')
        ->and($originalRun->refresh()->status)->toBe(AgentRunStatus::Failed)
        ->and(AgentRun::query()->whereBelongsTo($task)->count())->toBe(2);
});
