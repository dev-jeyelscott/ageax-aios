<?php

use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Models\User;
use App\ProjectStatus;
use App\ReviewStatus;
use App\TaskStatus;
use Inertia\Testing\AssertableInertia as Assert;

test('project tasks command center receives durable ordered task and git evidence', function () {
    $user = User::factory()->create();

    $project = Project::create([
        'name' => 'Task Command Center',
        'path' => '/tmp/task-command-center-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $secondTask = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-002',
        'position' => 2,
        'title' => 'Review the implementation',
        'objective' => 'Verify the second ordered task.',
        'acceptance_criteria' => [
            'The task is ready for deterministic review.',
        ],
        'implementation_prompt' => 'Implement the second task.',
        'context_capsule' => [],
        'status' => TaskStatus::ReadyForReview,
    ]);

    $firstTask = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Implement the foundation',
        'objective' => 'Implement the first ordered task.',
        'acceptance_criteria' => [
            'The foundation is implemented.',
        ],
        'implementation_prompt' => 'Implement the first task.',
        'context_capsule' => [],
        'status' => TaskStatus::Coding,
    ]);

    $firstAttempt = TaskAttempt::create([
        'task_id' => $firstTask->id,
        'number' => 2,
        'status' => 'coding',
        'started_at' => now(),
    ]);

    $secondAttempt = TaskAttempt::create([
        'task_id' => $secondTask->id,
        'number' => 1,
        'base_sha' => str_repeat('a', 40),
        'head_sha' => str_repeat('b', 40),
        'commit_sha' => str_repeat('c', 40),
        'status' => 'validated',
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    Review::create([
        'task_id' => $secondTask->id,
        'task_attempt_id' => $secondAttempt->id,
        'status' => ReviewStatus::InProgress,
        'started_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->has('project.tasks', 2)
            ->where('project.tasks.0.id', $firstTask->id)
            ->where('project.tasks.0.key', 'TASK-001')
            ->where('project.tasks.0.position', 1)
            ->where('project.tasks.0.status', 'coding')
            ->where(
                'project.tasks.0.attempts.0.number',
                $firstAttempt->number,
            )
            ->where('project.tasks.1.id', $secondTask->id)
            ->where('project.tasks.1.key', 'TASK-002')
            ->where('project.tasks.1.position', 2)
            ->where('project.tasks.1.status', 'ready_for_review')
            ->where('project.tasks.1.attempts.0.number', 1)
            ->where(
                'project.tasks.1.reviews.0.status',
                'in_progress',
            )
            ->where(
                'project.git_evidence.task.key',
                'TASK-002',
            )
            ->where(
                'project.git_evidence.attempt_number',
                $secondAttempt->number,
            )
            ->where(
                'project.git_evidence.base_sha',
                str_repeat('a', 40),
            )
            ->where(
                'project.git_evidence.head_sha',
                str_repeat('b', 40),
            )
            ->where(
                'project.git_evidence.commit_sha',
                str_repeat('c', 40),
            ));
});
