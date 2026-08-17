<?php

use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\ProjectStatus;

test('an authenticated user can delete a project', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);

    $this->actingAs(User::factory()->create())
        ->delete(route('projects.destroy', $project))
        ->assertRedirect(route('projects.index'));

    expect(Project::query()->find($project->id))->toBeNull()
        ->and(AuditEvent::query()->where('event_type', 'project.deleted')->exists())->toBeTrue();
});

test('deleting a project cascades its tasks', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Coding task',
        'objective' => 'Attempt implementation.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => 'queued',
    ]);

    $this->actingAs(User::factory()->create())
        ->delete(route('projects.destroy', $project))
        ->assertRedirect(route('projects.index'));

    expect(Task::query()->find($task->id))->toBeNull();
});
