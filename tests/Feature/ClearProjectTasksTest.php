<?php

use App\Actions\ClaimTask;
use App\Actions\ClearProjectTasks;
use App\AgentRole;
use App\Models\AuditEvent;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Models\User;
use App\ProjectStatus;
use App\TaskStatus;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a running Project fixture for durable Task clearing tests.
 */
function clearTasksProject(string $name = 'Clear Tasks Project'): Project
{
    return Project::create([
        'name' => $name,
        'path' => '/tmp/clear-tasks-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Create a Task fixture with an explicit durable status and optional Phase.
 */
function clearTasksTask(
    Project $project,
    int $position,
    TaskStatus $status = TaskStatus::Queued,
    ?Phase $phase = null,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase?->id,
        'key' => 'CLEAR-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT),
        'position' => $position,
        'title' => "Clear task {$position}",
        'objective' => 'Prove clearing retains durable task history.',
        'acceptance_criteria' => ['The task remains historically available.'],
        'implementation_prompt' => 'Implement the task.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

test('new tasks default to non-cleared and clearing preserves durable status and evidence', function (): void {
    $project = clearTasksProject();
    $task = clearTasksTask($project, 1, TaskStatus::Failed);

    $attempt = TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'status' => 'failed',
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    AuditEvent::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'event_type' => 'task.failed',
        'payload' => [],
        'occurred_at' => now(),
    ]);

    expect($task->is_cleared)->toBeFalse();

    app(ClearProjectTasks::class)->handle($project);

    expect($task->refresh()->is_cleared)->toBeTrue()
        ->and($task->status)->toBe(TaskStatus::Failed)
        ->and($task->attempts()->whereKey($attempt)->exists())->toBeTrue()
        ->and($task->auditEvents()->where('event_type', 'task.failed')->exists())->toBeTrue()
        ->and(
            AuditEvent::query()
                ->whereBelongsTo($project)
                ->where('event_type', 'project.tasks_cleared')
                ->first()?->payload['affected_task_count'],
        )
        ->toBe(1);
});

test('clearing is project-isolated and idempotent', function (): void {
    $project = clearTasksProject();
    $otherProject = clearTasksProject('Unrelated Project');
    $task = clearTasksTask($project, 1);
    $otherTask = clearTasksTask($otherProject, 1);

    expect(app(ClearProjectTasks::class)->handle($project))->toBe(1)
        ->and(app(ClearProjectTasks::class)->handle($project))->toBe(0)
        ->and($task->refresh()->is_cleared)->toBeTrue()
        ->and($otherTask->refresh()->is_cleared)->toBeFalse()
        ->and(
            AuditEvent::query()
                ->whereBelongsTo($project)
                ->where('event_type', 'project.tasks_cleared')
                ->count(),
        )
        ->toBe(2);
});

test('the authenticated project clear route invokes the durable action', function (): void {
    $project = clearTasksProject();
    $task = clearTasksTask($project, 1);

    $this->post(route('projects.tasks.clear', $project))
        ->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->post(route('projects.tasks.clear', $project))
        ->assertRedirect(route('projects.show', $project));

    expect($task->refresh()->is_cleared)->toBeTrue()
        ->and(
            AuditEvent::query()
                ->whereBelongsTo($project)
                ->where('event_type', 'project.tasks_cleared')
                ->exists(),
        )
        ->toBeTrue();
});

test('clearing refuses active execution without mutating tasks', function (TaskStatus $status): void {
    $project = clearTasksProject();
    $task = clearTasksTask($project, 1, $status);

    try {
        app(ClearProjectTasks::class)->handle($project);

        $this->fail('Expected active Task clearing to be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['tasks'] ?? [])->toBe([
            "Tasks cannot be cleared while {$task->key} is {$status->value}. Wait for active execution to finish before clearing the project queue.",
        ]);
    }

    expect($task->refresh()->is_cleared)->toBeFalse()
        ->and($task->status)->toBe($status);
})->with([
    TaskStatus::Coding,
    TaskStatus::Validating,
    TaskStatus::Reviewing,
]);

test('cleared coder and reviewer candidates cannot be claimed', function (): void {
    $project = clearTasksProject();

    foreach (
        [
            TaskStatus::Queued,
            TaskStatus::ChangesRequired,
            TaskStatus::Failed,
            TaskStatus::Interrupted,
        ] as $position => $status
    ) {
        clearTasksTask($project, $position + 1, $status)
            ->update(['is_cleared' => true]);
    }

    clearTasksTask($project, 5, TaskStatus::ReadyForReview)
        ->update(['is_cleared' => true]);

    expect(app(ClaimTask::class)->handle($project, AgentRole::Coder))->toBeNull()
        ->and(app(ClaimTask::class)->handle($project, AgentRole::Reviewer))->toBeNull();
});

test('cleared tasks cannot be claimed and do not hold the current phase open', function (): void {
    $project = clearTasksProject();

    $firstPhase = Phase::create([
        'project_id' => $project->id,
        'position' => 1,
        'title' => 'Cleared phase',
        'objective' => 'Historical work.',
    ]);

    $secondPhase = Phase::create([
        'project_id' => $project->id,
        'position' => 2,
        'title' => 'Active phase',
        'objective' => 'Operational work.',
    ]);

    $clearedCoder = clearTasksTask(
        $project,
        1,
        TaskStatus::Queued,
        $firstPhase,
    );

    $clearedReviewer = clearTasksTask(
        $project,
        2,
        TaskStatus::ReadyForReview,
        $firstPhase,
    );

    $activeTask = clearTasksTask(
        $project,
        3,
        TaskStatus::Queued,
        $secondPhase,
    );

    $clearedCoder->update(['is_cleared' => true]);
    $clearedReviewer->update(['is_cleared' => true]);

    expect(app(ClaimTask::class)->handle($project, AgentRole::Coder)?->id)
        ->toBe($activeTask->id)
        ->and(app(ClaimTask::class)->handle($project, AgentRole::Reviewer))
        ->toBeNull();
});

test('project payload and dashboard workload exclude cleared tasks while task history stays accessible', function (): void {
    $user = User::factory()->create();
    $project = clearTasksProject();
    $task = clearTasksTask($project, 1);

    $task->update(['is_cleared' => true]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->has('project.tasks', 0)
                ->has('project.audit_events'),
        );

    AuditEvent::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'event_type' => 'task.queued',
        'payload' => [],
        'occurred_at' => now()->addSecond(),
    ]);

    $this->actingAs($user)
        ->get(route('projects.tasks.show', [$project, $task]))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page->where('task.id', $task->id),
        );

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('summary.open_tasks', 0)
                ->where('workflow.queued', 0)
                ->has('recent_activity')
                ->where('recent_activity.0.task.id', $task->id),
        );
});
