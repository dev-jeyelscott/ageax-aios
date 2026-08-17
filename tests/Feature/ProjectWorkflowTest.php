<?php

use App\Actions\ClaimTask;
use App\Actions\RequeueBlockedTask;
use App\Actions\TransitionTask;
use App\AgentRole;
use App\Models\AgentWorker;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\TaskWorkflow;
use App\TaskStatus;
use Illuminate\Support\Facades\Process;

function createWorkflowTask(Project $project, int $position): Task
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

test('phase-less legacy tasks keep approval-gated dependency behavior', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $firstTask = createWorkflowTask($project, 1);
    $secondTask = createWorkflowTask($project, 2);
    $secondTask->dependencies()->attach($firstTask);

    $claimTask = app(ClaimTask::class);
    $transitionTask = app(TransitionTask::class);

    expect($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($firstTask->id);

    $transitionTask->handle($firstTask, TaskStatus::Validating);
    $transitionTask->handle($firstTask, TaskStatus::ReadyForReview);

    expect($claimTask->handle($project, AgentRole::Coder))->toBeNull();
    expect($claimTask->handle($project, AgentRole::Reviewer)?->id)->toBe($firstTask->id);

    $transitionTask->handle($firstTask, TaskStatus::Done);

    expect($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($secondTask->id);
});

test('a rejected review returns the same task to the coder with a legal transition', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = createWorkflowTask($project, 1);
    $claimTask = app(ClaimTask::class);
    $transitionTask = app(TransitionTask::class);

    $claimTask->handle($project, AgentRole::Coder);
    $transitionTask->handle($task, TaskStatus::Validating);
    $transitionTask->handle($task, TaskStatus::ReadyForReview);
    $claimTask->handle($project, AgentRole::Reviewer);
    $transitionTask->handle($task, TaskStatus::ChangesRequired);

    expect($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($task->id);
});

test('an exhausted reviewer operational retry blocks and can be requeued for review', function () {
    config()->set('aios.max_reviewer_attempts', 1);
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = createWorkflowTask($project, 1);
    app(TransitionTask::class)->handle($task, TaskStatus::Coding);
    app(TransitionTask::class)->handle($task, TaskStatus::Validating);
    app(TransitionTask::class)->handle($task, TaskStatus::ReadyForReview);
    app(TransitionTask::class)->handle($task, TaskStatus::Reviewing);

    app(TaskWorkflow::class)->recordReviewerOperationalFailure($task, null, ['reason' => 'invalid_structured_decision', 'exit_code' => 0]);

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($task->auditEvents()->where('event_type', 'review.retry_exhausted')->exists())->toBeTrue();

    app(RequeueBlockedTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::ReadyForReview);
});

test('coder batches the current phase before reviewer claims tasks in position order', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $firstPhase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Build the foundation.']);
    $secondPhase = Phase::create(['project_id' => $project->id, 'position' => 2, 'title' => 'Delivery', 'objective' => 'Deliver the feature.']);

    $firstTask = createWorkflowTask($project, 1);
    $secondTask = createWorkflowTask($project, 2);
    $thirdTask = createWorkflowTask($project, 3);

    $firstTask->update(['phase_id' => $firstPhase->id]);
    $secondTask->update(['phase_id' => $firstPhase->id]);
    $thirdTask->update(['phase_id' => $secondPhase->id]);

    $secondTask->dependencies()->attach($firstTask);

    $claimTask = app(ClaimTask::class);
    $transitionTask = app(TransitionTask::class);

    expect($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($firstTask->id);

    $transitionTask->handle($firstTask, TaskStatus::Validating);
    $transitionTask->handle($firstTask, TaskStatus::ReadyForReview);

    expect($claimTask->handle($project, AgentRole::Reviewer))->toBeNull()
        ->and($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($secondTask->id);

    $transitionTask->handle($secondTask, TaskStatus::Validating);
    $transitionTask->handle($secondTask, TaskStatus::ReadyForReview);

    expect(
        $claimTask->handle($project, AgentRole::Coder),
    )->toBeNull('the next phase must remain closed even when its first task has no explicit dependency');

    $firstReviewTask = $claimTask->handle($project, AgentRole::Reviewer);

    expect($firstReviewTask?->id)->toBe($firstTask->id)
        ->and($claimTask->handle($project, AgentRole::Reviewer))->toBeNull();

    app(TaskWorkflow::class)->approveTask($firstReviewTask);

    $secondReviewTask = $claimTask->handle($project, AgentRole::Reviewer);

    expect($firstTask->refresh()->status)->toBe(TaskStatus::Done)
        ->and($secondReviewTask?->id)->toBe($secondTask->id);

    app(TaskWorkflow::class)->approveTask($secondReviewTask);

    expect($secondTask->refresh()->status)->toBe(TaskStatus::Done)
        ->and($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($thirdTask->id);
});

test('a same-phase dependency is satisfied once its dependency reaches ready for review', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $phase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Build the foundation.']);

    $firstTask = createWorkflowTask($project, 1);
    $secondTask = createWorkflowTask($project, 2);
    $firstTask->update(['phase_id' => $phase->id]);
    $secondTask->update(['phase_id' => $phase->id]);
    $secondTask->dependencies()->attach($firstTask);

    $claimTask = app(ClaimTask::class);
    $transitionTask = app(TransitionTask::class);

    expect($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($firstTask->id);

    $transitionTask->handle($firstTask, TaskStatus::Validating);
    $transitionTask->handle($firstTask, TaskStatus::ReadyForReview);

    expect($claimTask->handle($project, AgentRole::Reviewer))->toBeNull()
        ->and($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($secondTask->id);
});

test('changes required closes the phase review gate and returns control to the coder', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $phase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Build the foundation.']);

    $firstTask = createWorkflowTask($project, 1);
    $secondTask = createWorkflowTask($project, 2);
    $thirdTask = createWorkflowTask($project, 3);

    foreach ([$firstTask, $secondTask, $thirdTask] as $task) {
        $task->update([
            'phase_id' => $phase->id,
            'status' => TaskStatus::ReadyForReview,
        ]);
    }

    $claimTask = app(ClaimTask::class);
    $transitionTask = app(TransitionTask::class);

    $firstReviewTask = $claimTask->handle($project, AgentRole::Reviewer);
    app(TaskWorkflow::class)->approveTask($firstReviewTask);

    $secondReviewTask = $claimTask->handle($project, AgentRole::Reviewer);

    expect($secondReviewTask?->id)->toBe($secondTask->id);

    $transitionTask->handle($secondReviewTask, TaskStatus::ChangesRequired);

    expect($thirdTask->refresh()->status)->toBe(TaskStatus::ReadyForReview)
        ->and($claimTask->handle($project, AgentRole::Reviewer))->toBeNull()
        ->and($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($secondTask->id);

    $transitionTask->handle($secondTask, TaskStatus::Validating);
    $transitionTask->handle($secondTask, TaskStatus::ReadyForReview);

    expect($claimTask->handle($project, AgentRole::Reviewer)?->id)->toBe($secondTask->id);
});

test('reviewer worker cooldown blocks the next phase review claim for 300 seconds', function () {
    config()->set('aios.worker_task_cooldown_seconds', 300);
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));

    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $phase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Build the foundation.']);

    foreach ([AgentRole::Coder, AgentRole::Reviewer] as $role) {
        AgentWorker::create([
            'project_id' => $project->id,
            'role' => $role,
            'status' => 'idle',
        ]);
    }

    $firstTask = createWorkflowTask($project, 1);
    $secondTask = createWorkflowTask($project, 2);

    foreach ([$firstTask, $secondTask] as $task) {
        $task->update([
            'phase_id' => $phase->id,
            'status' => TaskStatus::ReadyForReview,
        ]);

        TaskAttempt::create([
            'task_id' => $task->id,
            'number' => 1,
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    $review = [
        'outcome' => 'approved',
        'summary' => 'All acceptance criteria are met.',
        'findings' => [],
    ];

    Process::fake([
        '*' => Process::result(
            output: json_encode([
                'type' => 'item.completed',
                'item' => [
                    'type' => 'agent_message',
                    'text' => json_encode($review, JSON_THROW_ON_ERROR),
                ],
            ], JSON_THROW_ON_ERROR),
        ),
    ]);

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($firstTask->refresh()->status)->toBe(TaskStatus::Done)
        ->and($secondTask->refresh()->status)->toBe(TaskStatus::ReadyForReview);

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($secondTask->refresh()->status)
        ->toBe(TaskStatus::ReadyForReview, 'the reviewer must remain on its configured 300-second cooldown');

    $this->travel(301)->seconds();

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($secondTask->refresh()->status)->toBe(TaskStatus::Done);
});
