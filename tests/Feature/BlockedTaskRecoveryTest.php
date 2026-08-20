<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Models\User;
use App\ProjectStatus;
use App\TaskStatus;

test('an authenticated user can requeue a blocked task without changing its working tree', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Blocked task',
        'objective' => 'Recover the task.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::Blocked,
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('projects.tasks.requeue', [$project, $task]))
        ->assertRedirect(route('projects.show', $project));

    expect($task->refresh()->status)->toBe(TaskStatus::ChangesRequired)
        ->and($project->auditEvents()->where('task_id', $task->id)->where('event_type', 'task.requeued')->exists())->toBeTrue();
});

test('an exhausted reviewer retry is requeued for review instead of coder changes', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Blocked review',
        'objective' => 'Recover the review.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::Blocked,
    ]);
    TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
    $project->auditEvents()->create(['task_id' => $task->id, 'event_type' => 'review.retry_exhausted', 'payload' => ['attempt_number' => 1], 'occurred_at' => now()]);

    $this->actingAs(User::factory()->create())
        ->post(route('projects.tasks.requeue', [$project, $task]))
        ->assertRedirect(route('projects.show', $project));

    expect($task->refresh()->status)->toBe(TaskStatus::ReadyForReview);
});

test('an authenticated user can skip a blocked task with a reason', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Blocked task',
        'objective' => 'Recover the task.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::Blocked,
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('projects.tasks.skip', [$project, $task]), [
            'reason' => 'Requires physical device hardware unavailable in this environment.',
        ])
        ->assertRedirect(route('projects.tasks.show', [$project, $task]));

    expect($task->refresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($project->auditEvents()->where('task_id', $task->id)->where('event_type', 'task.skipped')->exists())->toBeTrue();
});

test('skipping a task without a reason is rejected', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Blocked task',
        'objective' => 'Recover the task.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::Blocked,
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('projects.tasks.skip', [$project, $task]), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked);
});

test('a non blocked task cannot be skipped', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Queued task',
        'objective' => 'Not blocked.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('projects.tasks.skip', [$project, $task]), ['reason' => 'Not applicable.'])
        ->assertStatus(409);

    expect($task->refresh()->status)->toBe(TaskStatus::Queued);
});
