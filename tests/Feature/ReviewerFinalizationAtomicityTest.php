<?php

use App\Actions\RunReviewerTask;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\ReviewStatus;
use App\Services\AuditLogger;
use App\Services\CodexCliRunner;
use App\Services\StaleWorkerRecovery;
use App\Services\TaskWorkflow;
use App\TaskStatus;
use Illuminate\Database\QueryException;

use function Pest\Laravel\mock;

function reviewerAtomicityProject(string $name = 'Reviewer finalization'): Project
{
    return Project::create([
        'name' => $name.' '.fake()->uuid(),
        'path' => '/tmp/reviewer-finalization-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

function reviewerAtomicityTask(Project $project, TaskStatus $status = TaskStatus::Reviewing): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Finalize Reviewer decision atomically',
        'objective' => 'Keep Reviewer durable truth failure-atomic.',
        'acceptance_criteria' => [
            'Exactly one authoritative Review exists for the implementation attempt.',
        ],
        'implementation_prompt' => 'Implement Reviewer finalization atomicity.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

function reviewerAtomicityAttempt(Task $task): TaskAttempt
{
    return TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'base_sha' => str_repeat('a', 40),
        'head_sha' => str_repeat('b', 40),
        'commit_sha' => str_repeat('c', 40),
        'status' => 'completed',
        'validation_results' => ['passed' => true],
        'changed_files' => ['app/Example.php'],
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
    ]);
}

/** @return array<int, array<string, string>> */
function reviewerAtomicityFindings(): array
{
    return [[
        'severity' => 'high',
        'location' => 'app/Example.php',
        'current_implementation' => 'The required guard is missing.',
        'expected_implementation' => 'The required guard is present.',
        'why_incorrect' => 'The acceptance criterion is not met.',
        'required_fix' => 'Add the required guard.',
        'verification_requirement' => 'Run the focused regression test.',
        'implementation_fix_context' => 'Preserve existing workflow semantics.',
    ]];
}

test('Reviewer decision persistence rolls back when workflow finalization fails', function () {
    $project = reviewerAtomicityProject();
    $task = reviewerAtomicityTask($project);
    $attempt = reviewerAtomicityAttempt($task);

    $faultingAudit = new class extends AuditLogger
    {
        /** @param array<string, mixed> $payload */
        public function record(
            string $eventType,
            array $payload = [],
            ?Project $project = null,
            ?Task $task = null,
        ): AuditEvent {
            if ($eventType === 'review.completed') {
                throw new RuntimeException(
                    'Injected failure after Review persistence and before Task finalization.',
                );
            }

            return parent::record($eventType, $payload, $project, $task);
        }
    };

    app()->instance(AuditLogger::class, $faultingAudit);
    app()->forgetInstance(TaskWorkflow::class);

    try {
        expect(fn () => app(TaskWorkflow::class)->finalizeReviewerDecision(
            $task,
            $attempt,
            ReviewStatus::ChangesRequired,
            'A required check is missing.',
            reviewerAtomicityFindings(),
        ))->toThrow(RuntimeException::class, 'Injected failure');
    } finally {
        app()->instance(AuditLogger::class, new AuditLogger);
        app()->forgetInstance(TaskWorkflow::class);
    }

    expect($task->refresh()->status)->toBe(TaskStatus::Reviewing)
        ->and(Review::query()->whereBelongsTo($task)->count())->toBe(0)
        ->and(ReviewFinding::query()->count())->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'review.finding_recorded')->count())->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'review.completed')->count())->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'task.rejected')->count())->toBe(0);

    app(TaskWorkflow::class)->finalizeReviewerDecision(
        $task->refresh(),
        $attempt->refresh(),
        ReviewStatus::ChangesRequired,
        'A required check is missing.',
        reviewerAtomicityFindings(),
    );

    expect($task->refresh()->status)->toBe(TaskStatus::ChangesRequired)
        ->and(Review::query()->whereBelongsTo($task)->count())->toBe(1)
        ->and(ReviewFinding::query()->count())->toBe(1)
        ->and($task->auditEvents()->where('event_type', 'review.finding_recorded')->count())->toBe(1)
        ->and($task->auditEvents()->where('event_type', 'review.completed')->count())->toBe(1)
        ->and($task->auditEvents()->where('event_type', 'task.rejected')->count())->toBe(1);
});

test('stale Reviewer recovery reconciles an already persisted decision without scheduling another review', function () {
    $project = reviewerAtomicityProject('Reviewer recovery');
    $task = reviewerAtomicityTask($project);
    $attempt = reviewerAtomicityAttempt($task);

    $review = Review::create([
        'task_id' => $task->id,
        'task_attempt_id' => $attempt->id,
        'status' => ReviewStatus::ChangesRequired,
        'summary' => 'A required check is missing.',
        'started_at' => now()->subMinutes(5),
        'completed_at' => now()->subMinutes(4),
    ]);

    $finding = ReviewFinding::create([
        'review_id' => $review->id,
        ...reviewerAtomicityFindings()[0],
    ]);

    app(AuditLogger::class)->record('review.finding_recorded', [
        'review_id' => $review->id,
        'review_finding_id' => $finding->id,
        'severity' => $finding->severity,
        'location' => $finding->location,
    ], $project, $task);

    app(AuditLogger::class)->record('review.completed', [
        'review_id' => $review->id,
        'outcome' => ReviewStatus::ChangesRequired->value,
        'finding_count' => 1,
        'attempt_number' => $attempt->number,
    ], $project, $task);

    AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'role' => AgentRole::Reviewer,
        'status' => AgentRunStatus::Completed,
        'attempt_number' => $attempt->number,
        'prompt_hash' => hash('sha256', 'persisted-review-before-crash'),
        'exit_code' => 0,
        'started_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(4),
    ]);

    Task::query()
        ->whereKey($task->id)
        ->update(['updated_at' => now()->subMinutes(5)]);

    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(1)
        ->and($task->refresh()->status)->toBe(TaskStatus::ChangesRequired)
        ->and($task->reviews()->count())->toBe(1)
        ->and($review->findings()->count())->toBe(1)
        ->and($task->auditEvents()->where('event_type', 'review.completed')->count())->toBe(1)
        ->and($task->auditEvents()->where('event_type', 'review.failed')->count())->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'review.retry_scheduled')->count())->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'review.finalization_recovered')->count())->toBe(1)
        ->and($task->auditEvents()->where('event_type', 'task.rejected')->count())->toBe(1)
        ->and(
            AgentRun::query()
                ->whereBelongsTo($task)
                ->where('role', AgentRole::Reviewer->value)
                ->count()
        )->toBe(1);
});

test('Reviewer execution reuses an already finalized attempt before launching a new harness decision', function () {
    $project = reviewerAtomicityProject('Reviewer preflight reconciliation');
    $task = reviewerAtomicityTask($project);
    $attempt = reviewerAtomicityAttempt($task);

    Review::create([
        'task_id' => $task->id,
        'task_attempt_id' => $attempt->id,
        'status' => ReviewStatus::Approved,
        'summary' => 'The implementation already has an authoritative approval.',
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
    ]);

    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->never();

    $execution = app(RunReviewerTask::class)->run($task, $attempt);

    expect($execution['exit_code'])->toBe(0)
        ->and($task->refresh()->status)->toBe(TaskStatus::Done)
        ->and($task->reviews()->count())->toBe(1)
        ->and(
            AgentRun::query()
                ->whereBelongsTo($task)
                ->where('role', AgentRole::Reviewer->value)
                ->count()
        )->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'review.started')->count())->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'review.finalization_recovered')->count())->toBe(1)
        ->and($task->auditEvents()->where('event_type', 'task.approved')->count())->toBe(1);
});

test('database uniqueness prevents two Reviews for one TaskAttempt', function () {
    $project = reviewerAtomicityProject('Reviewer uniqueness');
    $task = reviewerAtomicityTask($project);
    $attempt = reviewerAtomicityAttempt($task);

    Review::create([
        'task_id' => $task->id,
        'task_attempt_id' => $attempt->id,
        'status' => ReviewStatus::Approved,
        'summary' => 'First authoritative decision.',
        'started_at' => now()->subSecond(),
        'completed_at' => now(),
    ]);

    expect(fn () => Review::create([
        'task_id' => $task->id,
        'task_attempt_id' => $attempt->id,
        'status' => ReviewStatus::Approved,
        'summary' => 'Duplicate decision.',
        'started_at' => now()->subSecond(),
        'completed_at' => now(),
    ]))->toThrow(QueryException::class);
});
