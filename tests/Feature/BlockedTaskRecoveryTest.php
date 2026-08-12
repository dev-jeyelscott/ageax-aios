<?php

use App\Models\Project;
use App\Models\Task;
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
