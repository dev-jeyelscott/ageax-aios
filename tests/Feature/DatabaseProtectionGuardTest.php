<?php

use App\Actions\RunCoderTask;
use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\Exceptions\DatabaseProtectionFailed;
use App\Exceptions\UnsafeProjectPath;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\DatabaseBackup;
use App\Models\Project;
use App\Models\Task;
use App\ProjectStatus;
use App\Services\DatabaseBackupService;
use App\Services\DatabaseProtectionGuard;
use App\Services\WorkspacePathResolver;
use App\TaskStatus;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use ReflectionProperty;

function realDatabaseProtectionGuard(): DatabaseProtectionGuard
{
    return new DatabaseProtectionGuard(app(DatabaseBackupService::class), app(WorkspacePathResolver::class));
}

function protectedProject(): Project
{
    $path = sys_get_temp_dir().'/aios-db-protection-'.uniqid();
    mkdir($path, 0700, true);

    return Project::create(['name' => 'Protected', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
}

function protectedCodingTask(Project $project): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Coding task',
        'objective' => 'Implement.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::Coding,
    ]);
}

test('guard() blocks when an active restore lock file is present', function () {
    $backupPath = sys_get_temp_dir().'/aios-backup-'.uniqid();
    mkdir($backupPath, 0700, true);
    config()->set('aios.backup_path', $backupPath);
    file_put_contents($backupPath.'/'.config('aios.database_restore_lock_filename'), 'restoring');

    expect(fn () => realDatabaseProtectionGuard()->guard())->toThrow(DatabaseProtectionFailed::class);
});

test('guard() re-throws UnsafeProjectPath for a project outside the workspace before touching backups', function () {
    config()->set('aios.workspace_root', sys_get_temp_dir().'/aios-elsewhere-'.uniqid());
    $project = protectedProject();
    $backups = Mockery::mock(DatabaseBackupService::class);
    $backups->shouldNotReceive('latestVerified');
    $backups->shouldNotReceive('create');
    app()->instance(DatabaseBackupService::class, $backups);

    expect(fn () => realDatabaseProtectionGuard()->guard($project))->toThrow(UnsafeProjectPath::class);
});

test('guard() creates a fresh backup when no verified recovery point exists, then proceeds', function () {
    $backups = Mockery::mock(DatabaseBackupService::class);
    $backups->shouldReceive('latestVerified')->once()->andReturn(null);
    $backups->shouldReceive('create')->once()->with('database_protection_guard');
    app()->instance(DatabaseBackupService::class, $backups);

    realDatabaseProtectionGuard()->guard();
})->throwsNoExceptions();

test('guard() blocks when no verified recovery point exists and creating one fails', function () {
    $backups = Mockery::mock(DatabaseBackupService::class);
    $backups->shouldReceive('latestVerified')->once()->andReturn(null);
    $backups->shouldReceive('create')->once()->andThrow(new RuntimeException('disk full'));
    app()->instance(DatabaseBackupService::class, $backups);

    expect(fn () => realDatabaseProtectionGuard()->guard())->toThrow(DatabaseProtectionFailed::class);
});

test('guard() is satisfied by an existing fresh verified backup without creating a new one', function () {
    $existing = new DatabaseBackup(['status' => 'completed']);
    $backups = Mockery::mock(DatabaseBackupService::class);
    $backups->shouldReceive('latestVerified')->once()->andReturn($existing);
    $backups->shouldNotReceive('create');
    app()->instance(DatabaseBackupService::class, $backups);

    realDatabaseProtectionGuard()->guard();
})->throwsNoExceptions();

test('a failed database protection guard blocks both Codex and Claude Code identically before either process starts, and neither harness starts', function () {
    $guard = Mockery::mock(DatabaseProtectionGuard::class);
    $guard->shouldReceive('guard')->twice()->andThrow(new DatabaseProtectionFailed('No verified recovery point exists.'));
    app()->instance(DatabaseProtectionGuard::class, $guard);
    Process::fake();

    // Codex (legacy fallback: no Agent bound to the Coder role).
    $codexProject = protectedProject();
    $codexTask = protectedCodingTask($codexProject);
    app(RunCoderTask::class)->handle($codexTask);

    // Claude Code (explicit Agent bound to the Coder role).
    $claudeProject = protectedProject();
    $claudeTask = protectedCodingTask($claudeProject);
    $claudeAgent = Agent::factory()->for($claudeProject)->create(['role' => AgentRole::Coder, 'harness' => AgentHarnessIdentifier::ClaudeCode]);
    AgentWorker::create(['project_id' => $claudeProject->id, 'role' => AgentRole::Coder, 'agent_id' => $claudeAgent->id, 'status' => 'idle']);
    app(RunCoderTask::class)->handle($claudeTask);

    // Repository preflight (git status/rev-parse) legitimately runs before the try block that
    // hosts the guard; what must never run is either harness binary itself.
    Process::assertNotRan(fn (PendingProcess $process): bool => collect((new ReflectionProperty($process, 'command'))->getValue($process))
        ->contains(fn ($argument): bool => is_string($argument) && (str_contains($argument, (string) config('aios.codex_binary')) || str_contains($argument, (string) config('aios.claude_code_binary')))));
    expect($codexTask->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($claudeTask->refresh()->status)->toBe(TaskStatus::Failed)
        ->and(AgentRun::query()->whereBelongsTo($codexTask)->latest('id')->value('exit_code'))->toBe(-1)
        ->and(AgentRun::query()->whereBelongsTo($claudeTask)->latest('id')->value('exit_code'))->toBe(-1);
});
