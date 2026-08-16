<?php

use App\Actions\RequeueBlockedTask;
use App\Actions\TransitionTask;
use App\Models\Project;
use App\Models\Task;
use App\ProjectStatus;
use App\Services\AuditLogger;
use App\TaskStatus;

function requeueTestTask(Project $project, int $position): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT),
        'position' => $position,
        'title' => "Task {$position}",
        'objective' => "Implement task {$position}.",
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement the task.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

test('a task blocked by coder retry exhaustion is requeued back to the coder, not the reviewer', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = requeueTestTask($project, 1);
    $task = app(TransitionTask::class)->handle($task, TaskStatus::Blocked);
    app(AuditLogger::class)->record('task.coder_retry_exhausted', ['attempt_number' => 3, 'retry_count' => 3, 'retry_limit' => 3], $project, $task);

    app(RequeueBlockedTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::ChangesRequired);
});

test('the most recent exhaustion event decides the requeue target when both event types exist', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = requeueTestTask($project, 1);
    $task = app(TransitionTask::class)->handle($task, TaskStatus::Blocked);
    app(AuditLogger::class)->record('review.retry_exhausted', [], $project, $task);
    app(AuditLogger::class)->record('task.coder_retry_exhausted', ['attempt_number' => 3, 'retry_count' => 3, 'retry_limit' => 3], $project, $task);

    app(RequeueBlockedTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::ChangesRequired);
});

test('a task with no exhaustion evidence at all still defaults to changes required', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = requeueTestTask($project, 1);
    $task = app(TransitionTask::class)->handle($task, TaskStatus::Blocked);

    app(RequeueBlockedTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::ChangesRequired);
});
