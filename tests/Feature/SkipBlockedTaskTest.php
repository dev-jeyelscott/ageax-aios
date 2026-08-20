<?php

use App\Actions\SkipBlockedTask;
use App\Actions\TransitionTask;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Task;
use App\ProjectStatus;
use App\TaskStatus;
use Symfony\Component\HttpKernel\Exception\HttpException;

function skipTestTask(Project $project, int $position): Task
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

test('a blocked task is cancelled with a durable reason on skip', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = skipTestTask($project, 1);
    $task = app(TransitionTask::class)->handle($task, TaskStatus::Blocked);

    $skipped = app(SkipBlockedTask::class)->handle($task, 'Requires physical device hardware unavailable in this environment.');

    expect($skipped->status)->toBe(TaskStatus::Cancelled);

    $event = AuditEvent::query()->whereBelongsTo($task)->where('event_type', 'task.skipped')->latest('occurred_at')->first();

    expect($event)->not->toBeNull()
        ->and($event->payload['reason'])->toBe('Requires physical device hardware unavailable in this environment.')
        ->and($event->payload['dependents'])->toBe([]);
});

test('skipping a blocked task records affected dependents in the audit evidence', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = skipTestTask($project, 1);
    $dependent = skipTestTask($project, 2);
    $dependent->dependencies()->attach($task->id);
    $task = app(TransitionTask::class)->handle($task, TaskStatus::Blocked);

    app(SkipBlockedTask::class)->handle($task, 'Cannot be automated.');

    $event = AuditEvent::query()->whereBelongsTo($task)->where('event_type', 'task.skipped')->latest('occurred_at')->first();

    expect($event->payload['dependents'])->toBe([
        ['id' => $dependent->id, 'key' => $dependent->key, 'title' => $dependent->title],
    ]);
});

test('only a blocked task may be skipped', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = skipTestTask($project, 1);

    app(SkipBlockedTask::class)->handle($task, 'Not applicable.');
})->throws(HttpException::class);
