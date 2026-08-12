<?php

use App\Actions\ClaimTask;
use App\Actions\RunCoderTask;
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
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Pest\Laravel\mock;

/** @return array{path: string, base_sha: string} */
function gitIsolationRepository(string $trackedFile = 'README.md'): array
{
    $path = sys_get_temp_dir().'/aios-git-isolation-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);
    File::put($path.'/'.$trackedFile, 'baseline');
    Process::path($path)->run(['git', 'add', $trackedFile]);
    Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);
    $head = Process::path($path)->run(['git', 'rev-parse', 'HEAD']);

    return ['path' => $path, 'base_sha' => trim($head->output())];
}

function gitIsolationTask(Project $project, TaskStatus $status = TaskStatus::Queued): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Isolated coding task',
        'objective' => 'Implement only the task change.',
        'acceptance_criteria' => ['Only task-owned files are committed.'],
        'implementation_prompt' => 'Implement the isolated change.',
        'context_capsule' => [],
        'verification_commands' => [],
        'status' => $status,
    ]);
}

test('pre-existing unstaged changes block a new coder claim without modifying user work', function () {
    ['path' => $path, 'base_sha' => $baseSha] = gitIsolationRepository('feature.txt');
    File::put($path.'/feature.txt', 'user change');
    $project = Project::create(['name' => 'Example', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = gitIsolationTask($project);

    $claimed = app(ClaimTask::class)->handle($project, AgentRole::Coder);

    $status = Process::path($path)->run(['git', 'status', '--porcelain']);
    $head = Process::path($path)->run(['git', 'rev-parse', 'HEAD']);
    $audit = $task->auditEvents()->where('event_type', 'task.blocked_git_preflight')->firstOrFail();

    expect($claimed)->toBeNull()
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($task->attempts)->toHaveCount(0)
        ->and(File::get($path.'/feature.txt'))->toBe('user change')
        ->and(trim($head->output()))->toBe($baseSha)
        ->and($status->output())->toContain('feature.txt')
        ->and($project->refresh()->git_status)->toBe('dirty')
        ->and($audit->payload['mode'])->toBe('normal')
        ->and($audit->payload['repository']['working_tree_files'])->toBe(['feature.txt'])
        ->and($audit->payload['action'])->toContain('resolve the existing staged and working-tree changes');
});

test('pre-existing staged changes block a new coder claim and remain staged', function () {
    ['path' => $path, 'base_sha' => $baseSha] = gitIsolationRepository();
    File::put($path.'/user.txt', 'user staged change');
    Process::path($path)->run(['git', 'add', 'user.txt']);
    $project = Project::create(['name' => 'Example', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = gitIsolationTask($project);

    $claimed = app(ClaimTask::class)->handle($project, AgentRole::Coder);

    $staged = Process::path($path)->run(['git', 'diff', '--cached', '--name-only']);
    $head = Process::path($path)->run(['git', 'rev-parse', 'HEAD']);
    $audit = $task->auditEvents()->where('event_type', 'task.blocked_git_preflight')->firstOrFail();

    expect($claimed)->toBeNull()
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($task->attempts)->toHaveCount(0)
        ->and(File::get($path.'/user.txt'))->toBe('user staged change')
        ->and(trim($head->output()))->toBe($baseSha)
        ->and(trim($staged->output()))->toBe('user.txt')
        ->and($audit->payload['repository']['index_files'])->toBe(['user.txt']);
});

test('same-path ambiguity is blocked before Codex can edit an already dirty file', function () {
    ['path' => $path, 'base_sha' => $baseSha] = gitIsolationRepository('feature.txt');
    File::put($path.'/feature.txt', 'user version');
    $project = Project::create(['name' => 'Example', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'dirty']);
    $task = gitIsolationTask($project, TaskStatus::Coding);
    mock(CodexCliRunner::class)->shouldReceive('run')->never();

    $attempt = app(RunCoderTask::class)->handle($task);

    expect($attempt)->toBeNull()
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($task->attempts)->toHaveCount(0)
        ->and(File::get($path.'/feature.txt'))->toBe('user version')
        ->and(trim(Process::path($path)->run(['git', 'rev-parse', 'HEAD'])->output()))->toBe($baseSha);
});

test('a clean coder attempt stores its base SHA and commits exactly its own files', function () {
    ['path' => $path, 'base_sha' => $baseSha] = gitIsolationRepository();
    $project = Project::create(['name' => 'Example', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = gitIsolationTask($project);
    $claimed = app(ClaimTask::class)->handle($project, AgentRole::Coder);
    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (Project $runProject, string $prompt, mixed $onOutput) use ($path): array {
            File::put($path.'/task-one.txt', 'task one');
            File::put($path.'/task-two.txt', 'task two');

            return ['exit_code' => 0, 'output' => '{"summary":"implemented"}', 'error_output' => ''];
        });

    $attempt = app(RunCoderTask::class)->handle($claimed);

    $committedFiles = Process::path($path)->run(['git', 'show', '--format=', '--name-only', 'HEAD']);
    $status = Process::path($path)->run(['git', 'status', '--porcelain']);

    expect($attempt)->not->toBeNull()
        ->and($attempt->base_sha)->toBe($baseSha)
        ->and($attempt->changed_files)->toBe(['task-one.txt', 'task-two.txt'])
        ->and($attempt->commit_sha)->not->toBeNull()
        ->and($committedFiles->output())->toContain('task-one.txt')
        ->and($committedFiles->output())->toContain('task-two.txt')
        ->and(trim($status->output()))->toBe('')
        ->and($task->refresh()->status)->toBe(TaskStatus::ReadyForReview)
        ->and($project->refresh()->git_head_sha)->toBe($attempt->commit_sha)
        ->and($project->git_status)->toBe('clean');
});

test('interrupted coder recovery may continue the existing task diff from its recorded clean base', function () {
    ['path' => $path, 'base_sha' => $baseSha] = gitIsolationRepository();
    $project = Project::create(['name' => 'Example', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => 'working',
        'last_heartbeat_at' => now()->subMinutes(5),
    ]);
    $task = gitIsolationTask($project, TaskStatus::Coding);
    $interruptedAttempt = TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'base_sha' => $baseSha,
        'status' => 'running',
        'started_at' => now()->subMinutes(5),
    ]);
    AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', 'test'),
        'started_at' => now()->subMinutes(5),
    ]);
    File::put($path.'/feature.txt', 'interrupted draft');

    app(StaleWorkerRecovery::class)->recover($project, 60);

    expect($interruptedAttempt->refresh()->status)->toBe('interrupted')
        ->and($task->refresh()->status)->toBe(TaskStatus::Failed);

    $claimed = app(ClaimTask::class)->handle($project, AgentRole::Coder);
    expect($claimed?->id)->toBe($task->id)
        ->and($task->auditEvents()->where('event_type', 'task.recovery_git_state_accepted')->exists())->toBeTrue();

    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->once()
        ->withArgs(fn (Project $runProject, string $prompt, mixed $onOutput): bool => str_contains($prompt, 'interrupted-attempt recovery') && str_contains($prompt, $baseSha))
        ->andReturnUsing(function () use ($path): array {
            File::put($path.'/feature.txt', 'recovered implementation');

            return ['exit_code' => 0, 'output' => '{"summary":"recovered"}', 'error_output' => ''];
        });

    $recoveryAttempt = app(RunCoderTask::class)->handle($claimed);

    expect($recoveryAttempt)->not->toBeNull()
        ->and($recoveryAttempt->base_sha)->toBe($baseSha)
        ->and($recoveryAttempt->changed_files)->toBe(['feature.txt'])
        ->and($recoveryAttempt->commit_sha)->not->toBeNull()
        ->and(File::get($path.'/feature.txt'))->toBe('recovered implementation')
        ->and($task->refresh()->status)->toBe(TaskStatus::ReadyForReview)
        ->and(trim(Process::path($path)->run(['git', 'status', '--porcelain'])->output()))->toBe('');
});
