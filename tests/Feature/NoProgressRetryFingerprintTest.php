<?php

use App\Actions\ClaimTask;
use App\Actions\RequeueBlockedTask;
use App\Actions\RunCoderTask;
use App\Actions\RunReviewerTask;
use App\AgentRole;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\AuditLogger;
use App\Services\CodexCliRunner;
use App\Services\WorkflowRecoveryScanner;
use App\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Pest\Laravel\mock;

/** @return array{0: Project, 1: string, 2: string} */
function noProgressGitProject(): array
{
    $path = sys_get_temp_dir().'/aios-no-progress-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);
    File::put($path.'/README.md', '# No-progress test');
    Process::path($path)->run(['git', 'add', 'README.md']);
    Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);
    $head = trim(Process::path($path)->run(['git', 'rev-parse', 'HEAD'])->output());
    $project = Project::create([
        'name' => 'No Progress',
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    return [$project, $path, $head];
}

function noProgressTask(Project $project, string $key, int $position, TaskStatus $status): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => $position,
        'title' => "Task {$key}",
        'objective' => 'Exercise deterministic retry handling.',
        'acceptance_criteria' => ['Retry handling remains deterministic.'],
        'implementation_prompt' => 'Implement the focused task.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

function noProgressCompletedAttempt(Task $task, string $head): TaskAttempt
{
    return TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'base_sha' => $head,
        'head_sha' => $head,
        'commit_sha' => $head,
        'status' => 'completed',
        'validation_results' => ['passed' => true, 'checks' => []],
        'changed_files' => ['README.md'],
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
}

test('identical coder failures stop at the no-progress threshold before another harness execution', function () {
    config()->set('aios.max_coder_attempts', 5);
    config()->set('aios.no_progress_repeat_threshold', 1);
    [$project] = noProgressGitProject();
    $task = noProgressTask($project, 'TASK-001', 1, TaskStatus::Coding);
    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->twice()
        ->andReturn(
            ['exit_code' => 1, 'output' => '', 'error_output' => 'The same deterministic harness failure occurred.'],
            ['exit_code' => 1, 'output' => '', 'error_output' => 'The same deterministic harness failure occurred.'],
        );

    app(RunCoderTask::class)->handle($task);
    expect($task->refresh()->status)->toBe(TaskStatus::Failed);

    $retry = app(ClaimTask::class)->handle($project, AgentRole::Coder);
    expect($retry?->id)->toBe($task->id);
    app(RunCoderTask::class)->handle($retry);

    $attempts = $task->attempts()->orderBy('number')->get();
    $event = $task->auditEvents()->where('event_type', 'task.no_progress_detected')->firstOrFail();

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($attempts)->toHaveCount(2)
        ->and($attempts[0]->validation_results['no_progress']['failure_fingerprint'])->not->toBeNull()
        ->and($attempts[1]->validation_results['no_progress']['failure_fingerprint'])->toBe($attempts[0]->validation_results['no_progress']['failure_fingerprint'])
        ->and($attempts[1]->validation_results['no_progress']['consecutive_repeat_count'])->toBe(1)
        ->and($event->payload)->toMatchArray([
            'operation' => 'coder',
            'consecutive_repeat_count' => 1,
            'threshold' => 1,
        ])
        ->and(AgentRun::query()->whereBelongsTo($task)->where('role', AgentRole::Coder->value)->count())->toBe(2)
        ->and(app(ClaimTask::class)->handle($project, AgentRole::Coder))->toBeNull();
});

test('a materially changed coder failure signature continues the normal fresh retry lifecycle', function () {
    config()->set('aios.max_coder_attempts', 5);
    config()->set('aios.no_progress_repeat_threshold', 1);
    [$project] = noProgressGitProject();
    $task = noProgressTask($project, 'TASK-001', 1, TaskStatus::Coding);
    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->twice()
        ->andReturn(
            ['exit_code' => 1, 'output' => '', 'error_output' => 'First deterministic failure signature.'],
            ['exit_code' => 2, 'output' => '', 'error_output' => 'Second materially different failure signature.'],
        );

    app(RunCoderTask::class)->handle($task);
    $retry = app(ClaimTask::class)->handle($project, AgentRole::Coder);
    app(RunCoderTask::class)->handle($retry);

    $latestAttempt = $task->attempts()->latest('number')->firstOrFail();

    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($latestAttempt->validation_results['no_progress']['consecutive_repeat_count'])->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'task.no_progress_detected')->exists())->toBeFalse()
        ->and(AgentRun::query()->whereBelongsTo($task)->where('role', AgentRole::Coder->value)->count())->toBe(2);
});

test('repository content progress resets the coder repeat count even when the same files still fail', function () {
    config()->set('aios.max_coder_attempts', 5);
    config()->set('aios.no_progress_repeat_threshold', 1);
    [$project, $path] = noProgressGitProject();
    $task = noProgressTask($project, 'TASK-001', 1, TaskStatus::Coding);
    $runNumber = 0;
    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->twice()
        ->andReturnUsing(function () use (&$runNumber, $path): array {
            $runNumber++;
            File::put($path.'/feature.txt', $runNumber === 1 ? 'first draft' : 'second draft with progress');

            return ['exit_code' => 1, 'output' => '', 'error_output' => 'The same harness failure occurred after editing.'];
        });

    app(RunCoderTask::class)->handle($task);
    $retry = app(ClaimTask::class)->handle($project, AgentRole::Coder);
    expect($retry?->id)->toBe($task->id);
    app(RunCoderTask::class)->handle($retry);

    $attempts = $task->attempts()->orderBy('number')->get();

    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($attempts[0]->changed_files)->toBe(['feature.txt'])
        ->and($attempts[1]->changed_files)->toBe(['feature.txt'])
        ->and($attempts[0]->validation_results['no_progress']['repository_fingerprint'])->not->toBe($attempts[1]->validation_results['no_progress']['repository_fingerprint'])
        ->and($attempts[1]->validation_results['no_progress']['consecutive_repeat_count'])->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'task.no_progress_detected')->exists())->toBeFalse()
        ->and(File::get($path.'/feature.txt'))->toBe('second draft with progress');
});

test('the existing coder maximum attempt ceiling remains authoritative', function () {
    config()->set('aios.max_coder_attempts', 1);
    config()->set('aios.no_progress_repeat_threshold', 1);
    [$project] = noProgressGitProject();
    $task = noProgressTask($project, 'TASK-001', 1, TaskStatus::Coding);
    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturn(['exit_code' => 1, 'output' => '', 'error_output' => 'One failed execution.']);

    app(RunCoderTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($task->auditEvents()->where('event_type', 'task.coder_retry_exhausted')->exists())->toBeTrue()
        ->and($task->auditEvents()->where('event_type', 'task.no_progress_detected')->exists())->toBeFalse();
});

test('identical reviewer operational failures stop early without becoming implementation rejection', function () {
    config()->set('aios.max_reviewer_attempts', 5);
    config()->set('aios.no_progress_repeat_threshold', 1);
    [$project, , $head] = noProgressGitProject();
    $task = noProgressTask($project, 'TASK-001', 1, TaskStatus::Reviewing);
    $attempt = noProgressCompletedAttempt($task, $head);
    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->twice()
        ->andReturn(
            ['exit_code' => 1, 'output' => '', 'error_output' => 'The same reviewer process failure occurred.'],
            ['exit_code' => 1, 'output' => '', 'error_output' => 'The same reviewer process failure occurred.'],
        );

    app(RunReviewerTask::class)->run($task, $attempt);
    expect($task->refresh()->status)->toBe(TaskStatus::ReadyForReview);

    $retry = app(ClaimTask::class)->handle($project, AgentRole::Reviewer);
    expect($retry?->id)->toBe($task->id);
    app(RunReviewerTask::class)->run($retry, $attempt);

    $failedEvents = $task->auditEvents()->where('event_type', 'review.failed')->orderBy('id')->get();
    $event = $task->auditEvents()->where('event_type', 'task.no_progress_detected')->firstOrFail();

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($failedEvents)->toHaveCount(2)
        ->and($failedEvents[1]->payload['no_progress']['failure_fingerprint'])->toBe($failedEvents[0]->payload['no_progress']['failure_fingerprint'])
        ->and($failedEvents[1]->payload['no_progress']['consecutive_repeat_count'])->toBe(1)
        ->and($event->payload)->toMatchArray([
            'operation' => 'reviewer',
            'attempt_number' => 1,
            'consecutive_repeat_count' => 1,
            'threshold' => 1,
        ])
        ->and(AgentRun::query()->whereBelongsTo($task)->where('role', AgentRole::Reviewer->value)->count())->toBe(2)
        ->and($task->reviews()->count())->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'task.rejected')->exists())->toBeFalse()
        ->and(app(ClaimTask::class)->handle($project, AgentRole::Coder))->toBeNull();
});

test('a changed reviewer operational failure signature continues retrying', function () {
    config()->set('aios.max_reviewer_attempts', 5);
    config()->set('aios.no_progress_repeat_threshold', 1);
    [$project, , $head] = noProgressGitProject();
    $task = noProgressTask($project, 'TASK-001', 1, TaskStatus::Reviewing);
    $attempt = noProgressCompletedAttempt($task, $head);
    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->twice()
        ->andReturn(
            ['exit_code' => 1, 'output' => '', 'error_output' => 'First reviewer operational failure.'],
            ['exit_code' => 1, 'output' => '', 'error_output' => 'Second reviewer operational failure with changed evidence.'],
        );

    app(RunReviewerTask::class)->run($task, $attempt);
    $retry = app(ClaimTask::class)->handle($project, AgentRole::Reviewer);
    app(RunReviewerTask::class)->run($retry, $attempt);

    $latestFailure = $task->auditEvents()->where('event_type', 'review.failed')->orderByDesc('id')->firstOrFail();

    expect($task->refresh()->status)->toBe(TaskStatus::ReadyForReview)
        ->and($latestFailure->payload['no_progress']['consecutive_repeat_count'])->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'task.no_progress_detected')->exists())->toBeFalse()
        ->and($task->reviews()->count())->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'task.rejected')->exists())->toBeFalse();
});

test('the existing reviewer maximum attempt ceiling remains authoritative and operational failures never reject', function () {
    config()->set('aios.max_reviewer_attempts', 1);
    config()->set('aios.no_progress_repeat_threshold', 1);
    [$project, , $head] = noProgressGitProject();
    $task = noProgressTask($project, 'TASK-001', 1, TaskStatus::Reviewing);
    $attempt = noProgressCompletedAttempt($task, $head);
    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturn(['exit_code' => 1, 'output' => '', 'error_output' => 'Reviewer execution failed.']);

    app(RunReviewerTask::class)->run($task, $attempt);

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($task->auditEvents()->where('event_type', 'review.retry_exhausted')->exists())->toBeTrue()
        ->and($task->auditEvents()->where('event_type', 'task.no_progress_detected')->exists())->toBeFalse()
        ->and($task->reviews()->count())->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'task.rejected')->exists())->toBeFalse();
});

test('no-progress blocks recover to the correct workflow role and do not trigger another recovery harness incident', function () {
    config()->set('aios.recovery_stale_status_after_seconds', 1);
    [$project] = noProgressGitProject();
    $audit = app(AuditLogger::class);

    $coderTask = noProgressTask($project, 'TASK-001', 1, TaskStatus::Blocked);
    $audit->record('task.no_progress_detected', [
        'operation' => 'coder',
        'failure_fingerprint' => str_repeat('a', 64),
        'consecutive_repeat_count' => 1,
        'threshold' => 1,
    ], $project, $coderTask);
    app(RequeueBlockedTask::class)->handle($coderTask);

    $reviewerTask = noProgressTask($project, 'TASK-002', 2, TaskStatus::Blocked);
    $audit->record('task.no_progress_detected', [
        'operation' => 'reviewer',
        'failure_fingerprint' => str_repeat('b', 64),
        'consecutive_repeat_count' => 1,
        'threshold' => 1,
    ], $project, $reviewerTask);
    app(RequeueBlockedTask::class)->handle($reviewerTask);

    $stuckTask = noProgressTask($project, 'TASK-003', 3, TaskStatus::Blocked);
    $audit->record('task.no_progress_detected', [
        'operation' => 'coder',
        'failure_fingerprint' => str_repeat('c', 64),
        'consecutive_repeat_count' => 1,
        'threshold' => 1,
    ], $project, $stuckTask);
    DB::table('tasks')->where('id', $stuckTask->id)->update(['updated_at' => now()->subMinutes(2)]);

    app(WorkflowRecoveryScanner::class)->scan($project);

    expect($coderTask->refresh()->status)->toBe(TaskStatus::ChangesRequired)
        ->and($reviewerTask->refresh()->status)->toBe(TaskStatus::ReadyForReview)
        ->and($stuckTask->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and(RecoveryIncident::query()->where('task_id', $stuckTask->id)->exists())->toBeFalse()
        ->and($coderTask->auditEvents()->where('event_type', 'task.requeued')->exists())->toBeTrue()
        ->and($reviewerTask->auditEvents()->where('event_type', 'task.requeued')->exists())->toBeTrue();
});
