<?php

use App\Actions\RequeueBlockedTask;
use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\ReviewStatus;
use App\Services\TaskWorkflow;
use App\TaskStatus;

function rejectedReviewLoopTask(): Task
{
    $project = Project::create([
        'name' => 'Review loop',
        'path' => '/tmp/review-loop-'.fake()->uuid(),
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
    ]);

    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Externally verified task',
        'objective' => 'Verify an external production integration.',
        'acceptance_criteria' => ['A real production integration is verified.'],
        'implementation_prompt' => 'Implement and verify the integration.',
        'context_capsule' => [],
        'status' => TaskStatus::ChangesRequired,
    ]);
}

function addRejectedNoProgressReview(Task $task, int $number, string $head = 'same-head'): Review
{
    $recordedAt = now()->subMinutes(10)->addSeconds($number);
    $attempt = TaskAttempt::create([
        'task_id' => $task->id,
        'number' => $number,
        'base_sha' => $head,
        'head_sha' => $head,
        'status' => 'completed',
        'validation_results' => [
            'task_contract' => ['fingerprint' => 'same-contract'],
        ],
        'changed_files' => [],
        'started_at' => $recordedAt,
        'finished_at' => $recordedAt,
    ]);

    return Review::create([
        'task_id' => $task->id,
        'task_attempt_id' => $attempt->id,
        'status' => ReviewStatus::ChangesRequired,
        'started_at' => $recordedAt,
        'completed_at' => $recordedAt,
    ]);
}

test('three unchanged reviewer rejections block a task with durable operator evidence', function () {
    $task = rejectedReviewLoopTask();
    addRejectedNoProgressReview($task, 1);
    addRejectedNoProgressReview($task, 2);
    addRejectedNoProgressReview($task, 3);

    $blocked = app(TaskWorkflow::class)->blockRepeatedRejectedReviews($task);
    $event = $task->auditEvents()->where('event_type', 'task.review_no_progress_blocked')->firstOrFail();

    expect($blocked)->toBeTrue()
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($event->payload)->toMatchArray([
            'threshold' => 3,
            'attempt_numbers' => [1, 2, 3],
            'head_sha' => 'same-head',
            'task_contract_fingerprint' => 'same-contract',
        ]);
});

test('finalized reviewer decisions automatically block an unchanged rejection loop', function () {
    $task = rejectedReviewLoopTask();
    $workflow = app(TaskWorkflow::class);

    foreach (range(1, 3) as $number) {
        $workflow->transition($task->refresh(), TaskStatus::Coding);
        $workflow->transition($task->refresh(), TaskStatus::ReadyForReview);
        $workflow->transition($task->refresh(), TaskStatus::Reviewing);

        $attempt = TaskAttempt::create([
            'task_id' => $task->id,
            'number' => $number,
            'base_sha' => 'same-head',
            'head_sha' => 'same-head',
            'status' => 'completed',
            'changed_files' => [],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $workflow->finalizeReviewerDecision(
            $task->refresh(),
            $attempt,
            ReviewStatus::ChangesRequired,
            'Verification evidence is still missing.',
            [[
                'severity' => 'high',
                'location' => 'tests/Feature/InventoryItemsTest.php',
                'current_implementation' => 'No verification evidence is recorded.',
                'expected_implementation' => 'Record the required verification evidence.',
                'why_incorrect' => 'The acceptance criteria remain unproven.',
                'required_fix' => 'Run and report the required checks.',
                'verification_requirement' => 'Provide passing command output.',
                'implementation_fix_context' => 'Use the task verification commands.',
            ]],
        );
    }

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($task->auditEvents()->where('event_type', 'task.review_no_progress_blocked')->firstOrFail()->payload)
        ->toMatchArray([
            'threshold' => 3,
            'attempt_numbers' => [1, 2, 3],
            'head_sha' => 'same-head',
        ]);
});

test('repository progress prevents the review loop guard from blocking a task', function () {
    $task = rejectedReviewLoopTask();
    addRejectedNoProgressReview($task, 1);
    addRejectedNoProgressReview($task, 2);
    addRejectedNoProgressReview($task, 3, 'new-head');

    expect(app(TaskWorkflow::class)->blockRepeatedRejectedReviews($task))->toBeFalse()
        ->and($task->refresh()->status)->toBe(TaskStatus::ChangesRequired)
        ->and($task->auditEvents()->where('event_type', 'task.review_no_progress_blocked')->exists())->toBeFalse();
});

test('manual requeue starts a new review loop evidence window', function () {
    $task = rejectedReviewLoopTask();
    addRejectedNoProgressReview($task, 1);
    addRejectedNoProgressReview($task, 2);
    addRejectedNoProgressReview($task, 3);
    app(TaskWorkflow::class)->blockRepeatedRejectedReviews($task);

    app(RequeueBlockedTask::class)->handle($task->refresh());

    $newReview = addRejectedNoProgressReview($task, 4);
    $newReview->update(['completed_at' => now()->addSecond()]);

    expect(app(TaskWorkflow::class)->blockRepeatedRejectedReviews($task))->toBeFalse()
        ->and($task->refresh()->status)->toBe(TaskStatus::ChangesRequired);
});
