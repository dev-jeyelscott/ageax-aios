<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskPlanningEscalation;
use App\ProjectStatus;
use App\Services\AuditLogger;
use App\Services\WorkflowRecoveryScanner;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function autoUnblockProject(): Project
{
    $path = sys_get_temp_dir().'/aios-auto-unblock-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);
    File::put($path.'/feature.txt', 'baseline');
    Process::path($path)->run(['git', 'add', 'feature.txt']);
    Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);

    return Project::create(['name' => 'Auto Unblock', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'dirty']);
}

function autoUnblockTask(Project $project): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Auto unblock task',
        'objective' => 'Resume once clean.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::Blocked,
    ]);
}

test('a task blocked by a dirty repository auto-unblocks once the tree is clean again', function () {
    $project = autoUnblockProject();
    $task = autoUnblockTask($project);
    app(AuditLogger::class)->record('task.blocked_dirty_repository', ['reason' => 'repository_not_clean'], $project, $task);

    app(WorkflowRecoveryScanner::class)->scan($project);

    expect($task->refresh()->status)->toBe(TaskStatus::ChangesRequired)
        ->and($project->auditEvents()->where('event_type', 'task.auto_unblocked')->exists())->toBeTrue();
});

test('a task blocked by a dirty repository stays blocked while the tree is still dirty', function () {
    $project = autoUnblockProject();
    $task = autoUnblockTask($project);
    app(AuditLogger::class)->record('task.blocked_dirty_repository', ['reason' => 'repository_not_clean'], $project, $task);
    File::put($project->path.'/feature.txt', 'still dirty');

    app(WorkflowRecoveryScanner::class)->scan($project);

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($project->auditEvents()->where('event_type', 'task.auto_unblocked')->exists())->toBeFalse();
});

test('a task blocked for a non-repository reason never auto-unblocks even if the tree is clean', function () {
    $project = autoUnblockProject();
    $task = autoUnblockTask($project);
    app(AuditLogger::class)->record('task.blocked_agent_misconfigured', ['reason' => 'agent not bound'], $project, $task);

    app(WorkflowRecoveryScanner::class)->scan($project);

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($project->auditEvents()->where('event_type', 'task.auto_unblocked')->exists())->toBeFalse();
});

test('a task with a pending planning revision stays blocked when its repository becomes clean', function () {
    $project = autoUnblockProject();
    $task = autoUnblockTask($project);
    app(AuditLogger::class)->record('task.blocked_dirty_repository', ['reason' => 'repository_not_clean'], $project, $task);
    TaskPlanningEscalation::create([
        'task_id' => $task->id, 'defect_type' => 'unsafe_verification_commands', 'fingerprint' => hash('sha256', 'pending-planning-revision'),
        'failure_evidence' => [], 'allowed_fields' => ['verification_commands'], 'status' => 'pending',
    ]);

    app(WorkflowRecoveryScanner::class)->scan($project);

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($project->auditEvents()->where('event_type', 'task.auto_unblocked')->exists())->toBeFalse();
});
