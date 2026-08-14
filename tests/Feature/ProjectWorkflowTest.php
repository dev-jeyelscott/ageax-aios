<?php

use App\Actions\ClaimTask;
use App\Actions\RequeueBlockedTask;
use App\Actions\TransitionTask;
use App\AgentRole;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\ProjectStatus;
use App\Services\TaskWorkflow;
use App\TaskStatus;

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

test('coder waits for reviewer approval before claiming the next task', function () {
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

test('the reviewer is called once after every task in a phase is ready', function () {
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
    $thirdTask->dependencies()->attach($secondTask);

    $claimTask = app(ClaimTask::class);
    $transitionTask = app(TransitionTask::class);

    expect($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($firstTask->id);
    $transitionTask->handle($firstTask, TaskStatus::Validating);
    $transitionTask->handle($firstTask, TaskStatus::ReadyForReview);

    expect($claimTask->handle($project, AgentRole::Reviewer))->toBeNull()
        ->and($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($secondTask->id);

    $transitionTask->handle($secondTask, TaskStatus::Validating);
    $transitionTask->handle($secondTask, TaskStatus::ReadyForReview);

    $reviewTask = $claimTask->handle($project, AgentRole::Reviewer);

    expect($reviewTask?->id)->toBe($secondTask->id);

    app(TaskWorkflow::class)->approvePhase($reviewTask);

    expect($firstTask->refresh()->status)->toBe(TaskStatus::Done)
        ->and($secondTask->refresh()->status)->toBe(TaskStatus::Done)
        ->and($firstTask->auditEvents()->where('event_type', 'task.approved')->exists())->toBeTrue()
        ->and($secondTask->auditEvents()->where('event_type', 'task.approved')->exists())->toBeTrue()
        ->and($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($thirdTask->id);
});

test('a completed roadmap task does not block phase review of remaining work', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $phase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Build the foundation.']);
    $completedTask = createWorkflowTask($project, 1);
    $reviewableTask = createWorkflowTask($project, 2);
    $completedTask->update(['phase_id' => $phase->id, 'status' => TaskStatus::Done, 'completed_at' => now()]);
    $reviewableTask->update(['phase_id' => $phase->id, 'status' => TaskStatus::ReadyForReview]);

    $claimedTask = app(ClaimTask::class)->handle($project, AgentRole::Reviewer);

    expect($claimedTask?->id)->toBe($reviewableTask->id);

    app(TaskWorkflow::class)->approvePhase($claimedTask);

    expect($completedTask->refresh()->status)->toBe(TaskStatus::Done)
        ->and($reviewableTask->refresh()->status)->toBe(TaskStatus::Done)
        ->and($completedTask->auditEvents()->where('event_type', 'task.approved')->doesntExist())->toBeTrue();
});

test('a rejected phase review returns only the final task to the coder', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $phase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Build the foundation.']);
    $firstTask = createWorkflowTask($project, 1);
    $finalTask = createWorkflowTask($project, 2);
    $firstTask->update(['phase_id' => $phase->id, 'status' => TaskStatus::ReadyForReview]);
    $finalTask->update(['phase_id' => $phase->id, 'status' => TaskStatus::Reviewing]);
    $finalTask->dependencies()->attach($firstTask);

    app(TransitionTask::class)->handle($finalTask, TaskStatus::ChangesRequired);

    expect($firstTask->refresh()->status)->toBe(TaskStatus::ReadyForReview)
        ->and(app(ClaimTask::class)->handle($project, AgentRole::Coder)?->id)->toBe($finalTask->id);
});
