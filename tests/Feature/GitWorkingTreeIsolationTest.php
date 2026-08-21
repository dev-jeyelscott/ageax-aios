<?php

use App\Actions\ClaimTask;
use App\Actions\RunCoderTask;
use App\AgentRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\CoderRepositoryGuard;
use App\Services\CodexCliRunner;
use App\Services\ProjectGitState;
use App\Services\TaskCommitter;
use App\Services\TaskValidator;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Pest\Laravel\mock;

function gitIsolationProject(): Project
{
    $path = sys_get_temp_dir().'/aios-git-isolation-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);
    File::put($path.'/feature.txt', 'baseline');
    Process::path($path)->run(['git', 'add', 'feature.txt']);
    Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);
    $head = trim(Process::path($path)->run(['git', 'rev-parse', 'HEAD'])->output());

    return Project::create([
        'name' => 'Git Isolation',
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
        'git_head_sha' => $head,
    ]);
}

function gitIsolationTask(Project $project, TaskStatus $status = TaskStatus::Queued): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Isolated change',
        'objective' => 'Change only task-owned files.',
        'acceptance_criteria' => ['The task change is isolated.'],
        'implementation_prompt' => 'Create task.txt.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

test('pre-existing unstaged changes block a new coder attempt without modifying user changes', function () {
    $project = gitIsolationProject();
    $task = gitIsolationTask($project);
    File::put($project->path.'/feature.txt', 'user change');
    $beforeStatus = Process::path($project->path)->run(['git', 'status', '--porcelain'])->output();

    $claimed = app(ClaimTask::class)->handle($project, AgentRole::Coder);

    $afterStatus = Process::path($project->path)->run(['git', 'status', '--porcelain'])->output();
    expect($claimed)->toBeNull()
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and(File::get($project->path.'/feature.txt'))->toBe('user change')
        ->and($afterStatus)->toBe($beforeStatus)
        ->and($task->auditEvents()->where('event_type', 'task.blocked_dirty_repository')->exists())->toBeTrue()
        ->and($task->auditEvents()->where('event_type', 'task.blocked_dirty_repository')->firstOrFail()->payload['action'])->toContain('Resolve the repository state manually');
});

test('a Claude Code local settings artifact does not block a coder claim', function () {
    $project = gitIsolationProject();
    $task = gitIsolationTask($project);
    File::ensureDirectoryExists($project->path.'/.claude');
    File::put($project->path.'/.claude/settings.local.json', '{"permissions":[]}');

    $claimed = app(ClaimTask::class)->handle($project, AgentRole::Coder);

    expect($claimed?->id)->toBe($task->id)
        ->and($task->refresh()->status)->toBe(TaskStatus::Coding)
        ->and(app(ProjectGitState::class)->inspect($project->path)['untracked_files'])->toBe([]);
});

test('pre-existing staged changes block a new coder attempt without modifying the index', function () {
    $project = gitIsolationProject();
    $task = gitIsolationTask($project);
    File::put($project->path.'/feature.txt', 'staged user change');
    Process::path($project->path)->run(['git', 'add', 'feature.txt']);
    $beforeIndex = Process::path($project->path)->run(['git', 'diff', '--cached', '--binary'])->output();

    $claimed = app(ClaimTask::class)->handle($project, AgentRole::Coder);

    $afterIndex = Process::path($project->path)->run(['git', 'diff', '--cached', '--binary'])->output();
    expect($claimed)->toBeNull()
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and(File::get($project->path.'/feature.txt'))->toBe('staged user change')
        ->and($afterIndex)->toBe($beforeIndex)
        ->and($task->auditEvents()->where('event_type', 'task.blocked_dirty_repository')->firstOrFail()->payload['staged_files'])->toBe(['feature.txt']);
});

test('task committer rejects an unexpected staged file before staging task files', function () {
    $project = gitIsolationProject();
    $task = gitIsolationTask($project, TaskStatus::Coding);
    File::put($project->path.'/task.txt', 'task-owned change');
    File::put($project->path.'/other.txt', 'unexpected staged change');
    Process::path($project->path)->run(['git', 'add', 'other.txt']);
    $baseSha = trim(Process::path($project->path)->run(['git', 'rev-parse', 'HEAD'])->output());

    $commit = app(TaskCommitter::class)->commit($task, ['task.txt']);

    expect($commit)->toBeNull()
        ->and(trim(Process::path($project->path)->run(['git', 'rev-parse', 'HEAD'])->output()))->toBe($baseSha)
        ->and(Process::path($project->path)->run(['git', 'diff', '--cached', '--name-only'])->output())->toContain('other.txt')
        ->and(Process::path($project->path)->run(['git', 'status', '--porcelain'])->output())->toContain('?? task.txt');
});

test('a clean coder attempt stores its base and commits exactly its own files', function () {
    $project = gitIsolationProject();
    $task = gitIsolationTask($project);
    $baseSha = $project->git_head_sha;

    mock(TaskValidator::class)
        ->shouldReceive('validate')
        ->once()
        ->andReturn(['passed' => true, 'checks' => ['deterministic_validation' => true]]);
    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (Project $runProject): array {
            File::put($runProject->path.'/task.txt', 'task-owned change');

            return [
                'exit_code' => 0,
                'output' => json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => '{"summary":"Implemented."}']], JSON_THROW_ON_ERROR),
                'error_output' => '',
            ];
        });

    $claimed = app(ClaimTask::class)->handle($project, AgentRole::Coder);
    $attempt = app(RunCoderTask::class)->handle($claimed);
    $committedFiles = array_values(array_filter(preg_split('/\R/', trim(Process::path($project->path)->run(['git', 'show', '--format=', '--name-only', 'HEAD'])->output())) ?: []));

    expect($attempt->base_sha)->toBe($baseSha)
        ->and($attempt->commit_sha)->not->toBeNull()
        ->and($attempt->changed_files)->toBe(['task.txt'])
        ->and($committedFiles)->toBe(['task.txt'])
        ->and(trim(Process::path($project->path)->run(['git', 'status', '--porcelain'])->output()))->toBe('')
        ->and($task->refresh()->status)->toBe(TaskStatus::ReadyForReview);
});

test('same-path ambiguity cannot occur because a dirty task path is blocked before claim', function () {
    $project = gitIsolationProject();
    $task = gitIsolationTask($project);
    File::put($project->path.'/feature.txt', 'pre-existing user edit on task path');

    $claimed = app(ClaimTask::class)->handle($project, AgentRole::Coder);

    expect($claimed)->toBeNull()
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and(File::get($project->path.'/feature.txt'))->toBe('pre-existing user edit on task path')
        ->and(Process::path($project->path)->run(['git', 'diff', '--', 'feature.txt'])->output())->toContain('pre-existing user edit on task path');
});

test('an interrupted attempt may resume its existing dirty task diff from the same base', function () {
    $project = gitIsolationProject();
    $task = gitIsolationTask($project, TaskStatus::Failed);
    $baseSha = $project->git_head_sha;
    File::put($project->path.'/feature.txt', 'interrupted task edit');
    TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'base_sha' => $baseSha,
        'status' => 'interrupted',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    $claimed = app(ClaimTask::class)->handle($project, AgentRole::Coder);

    expect($claimed?->id)->toBe($task->id)
        ->and($task->refresh()->status)->toBe(TaskStatus::Coding)
        ->and(File::get($project->path.'/feature.txt'))->toBe('interrupted task edit')
        ->and($task->auditEvents()->where('event_type', 'task.repository_recovery_allowed')->exists())->toBeTrue();
});

test('a bookkeeping block does not hide the latest resumable task-owned dirty attempt', function () {
    $project = gitIsolationProject();
    $task = gitIsolationTask($project, TaskStatus::ChangesRequired);
    $baseSha = $project->git_head_sha;
    File::put($project->path.'/docs.md', 'task-owned result');
    TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'base_sha' => $baseSha,
        'status' => 'failed',
        'changed_files' => ['docs.md'],
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
    ]);
    TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 2,
        'base_sha' => $baseSha,
        'status' => 'blocked',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    $preflight = app(CoderRepositoryGuard::class)->inspect($task->refresh());

    expect($preflight['allowed'])->toBeTrue()
        ->and($preflight['mode'])->toBe('recovery')
        ->and($preflight['recovery_attempt']?->number)->toBe(1);
});
