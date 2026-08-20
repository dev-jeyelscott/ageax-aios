<?php

use App\AgentHarness;
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
use App\Services\ReviewerHarnessDiagnostics;
use App\TaskComplexity;
use App\TaskStatus;
use App\TaskWorkType;
use Illuminate\Support\Str;

function p3021Project(string $name = 'P3-021 Reviewer diagnostics'): Project
{
    return Project::factory()->create([
        'name' => $name.' '.Str::uuid(),
        'path' => sys_get_temp_dir().'/ageax-p3-021-'.Str::uuid(),
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
    ]);
}

function p3021Task(
    Project $project,
    int $position,
    TaskWorkType $workType = TaskWorkType::Feature,
    TaskComplexity $complexity = TaskComplexity::Medium,
    TaskStatus $status = TaskStatus::Done,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'phase_id' => null,
        'key' => 'TASK-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT),
        'position' => $position,
        'title' => "Reviewer diagnostic task {$position}",
        'objective' => 'Provide durable Reviewer diagnostic evidence.',
        'work_type' => $workType,
        'complexity' => $complexity,
        'acceptance_criteria' => ['Reviewer diagnostics remain observational.'],
        'scope' => [],
        'constraints' => [],
        'relevant_paths' => [],
        'verification_commands' => [],
        'implementation_prompt' => 'Implement the bounded task.',
        'context_capsule' => [],
        'status' => $status,
        'completed_at' => $status === TaskStatus::Done ? now() : null,
    ]);
}

function p3021Attempt(Task $task, int $number): TaskAttempt
{
    return TaskAttempt::create([
        'task_id' => $task->id,
        'number' => $number,
        'base_sha' => str_repeat('a', 40),
        'head_sha' => str_repeat('b', 40),
        'commit_sha' => str_repeat('c', 40),
        'status' => 'completed',
        'validation_results' => ['passed' => true],
        'changed_files' => [],
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
    ]);
}

function p3021Audit(
    Task $task,
    string $eventType,
    int $attemptNumber,
    array $payload = [],
): AuditEvent {
    return AuditEvent::create([
        'project_id' => $task->project_id,
        'task_id' => $task->id,
        'event_type' => $eventType,
        'payload' => [
            ...$payload,
            'attempt_number' => $attemptNumber,
        ],
        'occurred_at' => now(),
    ]);
}

function p3021ReviewerRun(
    Task $task,
    int $attemptNumber,
    string $harness = 'codex',
    string $model = 'gpt-5',
    ?string $reasoningSetting = 'high',
    AgentRunStatus $status = AgentRunStatus::Completed,
    ?int $tokenUsage = 100,
    ?int $durationSeconds = 10,
    bool $withSnapshot = true,
): AgentRun {
    $finishedAt = $durationSeconds === null ? null : now();
    $startedAt = $finishedAt?->copy()->subSeconds($durationSeconds) ?? now()->subSeconds(10);

    return AgentRun::create([
        'project_id' => $task->project_id,
        'task_id' => $task->id,
        'role' => AgentRole::Reviewer,
        'harness' => $harness,
        'status' => $status,
        'attempt_number' => $attemptNumber,
        'prompt_hash' => hash('sha256', "reviewer:{$task->id}:{$attemptNumber}:".Str::uuid()),
        'configuration_snapshot' => $withSnapshot ? [
            'agent' => [
                'id' => null,
                'name' => 'Historical Reviewer',
                'role' => AgentRole::Reviewer->value,
                'harness' => $harness,
                'model' => $model,
                'reasoning_setting' => $reasoningSetting,
                'default_context' => null,
                'configuration_version' => 1,
            ],
        ] : null,
        'context_schema_version' => $withSnapshot ? 2 : null,
        'token_usage' => $tokenUsage,
        'exit_code' => $status === AgentRunStatus::Completed ? 0 : 1,
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
    ]);
}

function p3021Review(
    Task $task,
    TaskAttempt $attempt,
    ReviewStatus $status,
    int $findingCount = 0,
): Review {
    $review = Review::create([
        'task_id' => $task->id,
        'task_attempt_id' => $attempt->id,
        'status' => $status,
        'summary' => $status === ReviewStatus::Approved
            ? 'Implementation approved.'
            : 'Correct the actionable findings.',
        'started_at' => now()->subSecond(),
        'completed_at' => now(),
    ]);

    for ($index = 0; $index < $findingCount; $index++) {
        ReviewFinding::create([
            'review_id' => $review->id,
            'severity' => 'medium',
            'location' => 'app/Example.php:'.($index + 1),
            'current_implementation' => 'Current behavior is incorrect.',
            'expected_implementation' => 'Expected behavior is deterministic.',
            'why_incorrect' => 'The acceptance criterion is not met.',
            'required_fix' => 'Correct the bounded implementation.',
            'verification_requirement' => 'Run the focused regression test.',
            'implementation_fix_context' => 'Preserve existing workflow semantics.',
        ]);
    }

    p3021Audit($task, 'review.completed', $attempt->number, [
        'review_id' => $review->id,
        'outcome' => $status->value,
        'finding_count' => $findingCount,
    ]);

    return $review;
}

function p3021ValidReviewCycle(
    Task $task,
    TaskAttempt $attempt,
    ReviewStatus $status,
    string $harness = 'codex',
    string $model = 'gpt-5',
    ?string $reasoningSetting = 'high',
    int $tokenUsage = 100,
    int $durationSeconds = 10,
    int $findingCount = 0,
): Review {
    p3021Audit($task, 'review.started', $attempt->number);
    p3021ReviewerRun(
        task: $task,
        attemptNumber: $attempt->number,
        harness: $harness,
        model: $model,
        reasoningSetting: $reasoningSetting,
        tokenUsage: $tokenUsage,
        durationSeconds: $durationSeconds,
    );

    return p3021Review($task, $attempt, $status, $findingCount);
}

function p3021Diagnostics(
    Project $project,
    array $tasks,
    TaskWorkType $workType = TaskWorkType::Feature,
    TaskComplexity $complexity = TaskComplexity::Medium,
): array {
    return app(ReviewerHarnessDiagnostics::class)->calculate(
        project: $project,
        tasks: $tasks,
        workType: $workType,
        complexity: $complexity,
    );
}

test('operational and structured Reviewer failures remain separate from implementation rejection', function () {
    $project = p3021Project();

    $failedTask = p3021Task($project, 1);
    $failedAttempt = p3021Attempt($failedTask, 1);
    p3021Audit($failedTask, 'review.started', 1);
    p3021ReviewerRun(
        task: $failedTask,
        attemptNumber: 1,
        status: AgentRunStatus::Failed,
        tokenUsage: 25,
        durationSeconds: 5,
    );
    p3021Audit($failedTask, 'review.failed', 1, [
        'reason' => 'execution_failed',
        'retry_count' => 1,
    ]);

    $malformedTask = p3021Task($project, 2);
    p3021Attempt($malformedTask, 1);
    p3021Audit($malformedTask, 'review.started', 1);
    p3021ReviewerRun(
        task: $malformedTask,
        attemptNumber: 1,
        tokenUsage: 30,
        durationSeconds: 6,
    );
    p3021Audit($malformedTask, 'review.failed', 1, [
        'reason' => 'invalid_structured_decision',
        'retry_count' => 1,
    ]);

    $approvedTask = p3021Task($project, 3);
    $approvedAttempt = p3021Attempt($approvedTask, 1);
    p3021ValidReviewCycle(
        $approvedTask,
        $approvedAttempt,
        ReviewStatus::Approved,
        tokenUsage: 40,
        durationSeconds: 7,
    );

    $result = p3021Diagnostics($project, [
        $failedTask->refresh(),
        $malformedTask->refresh(),
        $approvedTask->refresh(),
    ]);

    expect($result['sample']['review_started_invocation_count'])
        ->toBe(3)
        ->and($result['sample']['valid_review_count'])
        ->toBe(1)
        ->and($result['sample']['operational_failure_count'])
        ->toBe(2)
        ->and($result['rates']['operational_success'])
        ->toBe(0.333333)
        ->and($result['rates']['structured_output_validity'])
        ->toBe(0.5)
        ->and($result['rates']['review_retry'])
        ->toBe(0.666667)
        ->and($result['rates']['changes_required']['value'])
        ->toBe(0.0)
        ->and($result['operational_failure_reasons'])
        ->toBe([
            'execution_failed' => 1,
            'invalid_structured_decision' => 1,
        ])
        ->and(Review::query()->whereBelongsTo($failedTask)->count())
        ->toBe(0)
        ->and(Review::query()->whereBelongsTo($malformedTask)->count())
        ->toBe(0)
        ->and($failedAttempt->refresh()->task_id)
        ->toBe($failedTask->id);
});

test('Reviewer retries lower first-attempt completion and retain retry token and duration cost', function () {
    $project = p3021Project();

    $retriedTask = p3021Task($project, 1);
    $retriedAttempt = p3021Attempt($retriedTask, 1);
    p3021Audit($retriedTask, 'review.started', 1);
    p3021ReviewerRun(
        task: $retriedTask,
        attemptNumber: 1,
        tokenUsage: 40,
        durationSeconds: 4,
    );
    p3021Audit($retriedTask, 'review.failed', 1, [
        'reason' => 'missing_structured_decision',
        'retry_count' => 1,
    ]);
    p3021Audit($retriedTask, 'review.retry_scheduled', 1, [
        'reason' => 'missing_structured_decision',
        'retry_count' => 1,
    ]);
    p3021Audit($retriedTask, 'review.started', 1);
    p3021ReviewerRun(
        task: $retriedTask,
        attemptNumber: 1,
        tokenUsage: 60,
        durationSeconds: 6,
    );
    p3021Review($retriedTask, $retriedAttempt, ReviewStatus::Approved);

    $firstPassTask = p3021Task($project, 2);
    $firstPassAttempt = p3021Attempt($firstPassTask, 1);
    p3021ValidReviewCycle(
        $firstPassTask,
        $firstPassAttempt,
        ReviewStatus::Approved,
        tokenUsage: 200,
        durationSeconds: 20,
    );

    $result = p3021Diagnostics($project, [
        $retriedTask->refresh(),
        $firstPassTask->refresh(),
    ]);
    $configuration = $result['configuration_diagnostics'][0];

    expect($configuration['review_retry_count'])
        ->toBe(1)
        ->and($configuration['rates']['review_retry'])
        ->toBe(0.333333)
        ->and($configuration['rates']['first_attempt_review_completion'])
        ->toBe(0.5)
        ->and($configuration['rates']['structured_output_validity'])
        ->toBe(0.666667)
        ->and($configuration['medians']['token_consumption'])
        ->toBe(150.0)
        ->and($configuration['medians']['duration_seconds'])
        ->toBe(15.0)
        ->and($configuration['telemetry']['retry_cost_policy'])
        ->toContain('including operational retry runs');
});

test('changes required follow-through links later Coder attempts to eventual approval without claiming ground truth', function () {
    $project = p3021Project();

    $resolvedTask = p3021Task($project, 1);
    $attemptOne = p3021Attempt($resolvedTask, 1);
    $changesReview = p3021ValidReviewCycle(
        $resolvedTask,
        $attemptOne,
        ReviewStatus::ChangesRequired,
        findingCount: 2,
    );
    $attemptTwo = p3021Attempt($resolvedTask, 2);
    $approvalReview = p3021ValidReviewCycle(
        $resolvedTask,
        $attemptTwo,
        ReviewStatus::Approved,
    );

    $unresolvedTask = p3021Task($project, 2, status: TaskStatus::ChangesRequired);
    $unresolvedAttempt = p3021Attempt($unresolvedTask, 1);
    p3021ValidReviewCycle(
        $unresolvedTask,
        $unresolvedAttempt,
        ReviewStatus::ChangesRequired,
        findingCount: 1,
    );

    $result = p3021Diagnostics($project, [
        $resolvedTask->refresh(),
        $unresolvedTask->refresh(),
    ]);
    $followThrough = $result['actionable_finding_follow_through'];
    $resolvedEvidence = collect($followThrough['evidence'])
        ->firstWhere('task_id', $resolvedTask->id);
    $unresolvedEvidence = collect($followThrough['evidence'])
        ->firstWhere('task_id', $unresolvedTask->id);

    expect($followThrough['changes_required_review_count'])
        ->toBe(2)
        ->and($followThrough['with_actionable_findings_count'])
        ->toBe(2)
        ->and($followThrough['with_corrected_coder_attempt_count'])
        ->toBe(1)
        ->and($followThrough['eventual_approval_count'])
        ->toBe(1)
        ->and($followThrough['eventual_approval_rate'])
        ->toBe(0.5)
        ->and($resolvedEvidence['changes_required_review_id'])
        ->toBe($changesReview->id)
        ->and($resolvedEvidence['corrected_coder_attempt']['id'])
        ->toBe($attemptTwo->id)
        ->and($resolvedEvidence['eventual_approval_review_id'])
        ->toBe($approvalReview->id)
        ->and($resolvedEvidence['status'])
        ->toBe('eventual_approval_observed')
        ->and($resolvedEvidence['evidence_scope'])
        ->toBe('chain_level_only')
        ->and($unresolvedEvidence['status'])
        ->toBe('awaiting_corrected_coder_attempt')
        ->and($followThrough['interpretation'])
        ->toContain('does not claim each individual finding');
});

test('comparable cohort fallback is explicit before Codex and Claude outcome divergence is shown', function () {
    $project = p3021Project();

    $highFeature = p3021Task(
        $project,
        1,
        workType: TaskWorkType::Feature,
        complexity: TaskComplexity::High,
    );
    $highAttempt = p3021Attempt($highFeature, 1);
    p3021ValidReviewCycle(
        $highFeature,
        $highAttempt,
        ReviewStatus::Approved,
        harness: AgentHarness::Codex->value,
        model: 'gpt-5',
        reasoningSetting: 'high',
    );

    $lowFeature = p3021Task(
        $project,
        2,
        workType: TaskWorkType::Bug,
        complexity: TaskComplexity::Low,
    );
    $lowAttempt = p3021Attempt($lowFeature, 1);
    p3021ValidReviewCycle(
        $lowFeature,
        $lowAttempt,
        ReviewStatus::ChangesRequired,
        harness: AgentHarness::ClaudeCode->value,
        model: 'claude-sonnet',
        reasoningSetting: 'high',
        findingCount: 1,
    );

    $otherProject = p3021Project('Other repository');
    $otherTask = p3021Task(
        $otherProject,
        1,
        workType: TaskWorkType::Feature,
        complexity: TaskComplexity::High,
    );
    $otherAttempt = p3021Attempt($otherTask, 1);
    p3021ValidReviewCycle(
        $otherTask,
        $otherAttempt,
        ReviewStatus::ChangesRequired,
        harness: AgentHarness::ClaudeCode->value,
        model: 'claude-sonnet',
        reasoningSetting: 'high',
        findingCount: 1,
    );

    $result = p3021Diagnostics(
        $project,
        [$highFeature->refresh(), $lowFeature->refresh(), $otherTask->refresh()],
        workType: TaskWorkType::Feature,
        complexity: TaskComplexity::High,
    );

    expect($result['fallback_evaluations'][0]['directly_comparable'])
        ->toBeFalse()
        ->and($result['fallback_evaluations'][0]['sample']['configuration_count'])
        ->toBe(1)
        ->and($result['selected_cohort']['fallback_level'])
        ->toBe(2)
        ->and($result['selected_cohort']['broadened_dimensions'])
        ->toBe(['work_type', 'complexity'])
        ->and($result['approval_consistency']['available'])
        ->toBeTrue()
        ->and($result['approval_consistency']['ground_truth'])
        ->toBe('not_available')
        ->and($result['codex_claude_divergence']['available'])
        ->toBeTrue()
        ->and($result['codex_claude_divergence']['absolute_rate_delta']['approval'])
        ->toBe(1.0)
        ->and($result['codex_claude_divergence']['ground_truth'])
        ->toBe('not_available')
        ->and($result['recommendation_policy']['automatic_routing'])
        ->toBeFalse();
});

test('legacy missing snapshot evidence remains unattributed and zero evidence does not fabricate rates', function () {
    $project = p3021Project();
    $legacyTask = p3021Task($project, 1);
    $legacyAttempt = p3021Attempt($legacyTask, 1);

    p3021Audit($legacyTask, 'review.started', 1);
    p3021ReviewerRun(
        task: $legacyTask,
        attemptNumber: 1,
        withSnapshot: false,
    );
    p3021Review($legacyTask, $legacyAttempt, ReviewStatus::Approved);

    $legacyResult = p3021Diagnostics($project, [$legacyTask->refresh()]);
    $emptyResult = p3021Diagnostics($project, []);

    expect($legacyResult['sample']['valid_review_count'])
        ->toBe(1)
        ->and($legacyResult['sample']['attributed_valid_review_count'])
        ->toBe(0)
        ->and($legacyResult['sample']['unattributed_cycle_count'])
        ->toBe(1)
        ->and($legacyResult['unattributed_cycles'][0]['configuration_status'])
        ->toBe('missing_snapshot')
        ->and($legacyResult['approval_consistency']['available'])
        ->toBeFalse()
        ->and($legacyResult['codex_claude_divergence']['available'])
        ->toBeFalse()
        ->and($emptyResult['sample']['review_cycle_count'])
        ->toBe(0)
        ->and($emptyResult['rates']['operational_success'])
        ->toBeNull()
        ->and($emptyResult['rates']['structured_output_validity'])
        ->toBeNull()
        ->and($emptyResult['rates']['changes_required']['value'])
        ->toBeNull();
});

test('Reviewer diagnostics are versioned deterministic and read only', function () {
    $project = p3021Project();
    $task = p3021Task($project, 1, status: TaskStatus::ChangesRequired);
    $attempt = p3021Attempt($task, 1);
    $review = p3021ValidReviewCycle(
        $task,
        $attempt,
        ReviewStatus::ChangesRequired,
        findingCount: 1,
    );

    $run = AgentRun::query()
        ->whereBelongsTo($task)
        ->where('role', AgentRole::Reviewer)
        ->firstOrFail();
    $taskStatusBefore = $task->getRawOriginal('status');
    $reviewCountBefore = Review::query()->whereBelongsTo($task)->count();
    $auditCountBefore = AuditEvent::query()->whereBelongsTo($task)->count();
    $snapshotBefore = $run->configuration_snapshot;

    $first = p3021Diagnostics($project, [$task->refresh()]);
    $second = p3021Diagnostics($project, [$task->refresh()]);

    expect($first)
        ->toBe($second)
        ->and($first['schema_version'])
        ->toBe(ReviewerHarnessDiagnostics::SchemaVersion)
        ->and($first['methodology_version'])
        ->toBe(ReviewerHarnessDiagnostics::MethodologyVersion)
        ->and($first['fallback_policy']['version'])
        ->toBe(ReviewerHarnessDiagnostics::FallbackPolicyVersion)
        ->and($first['methodology']['changes_required']['interpretation'])
        ->toContain('never a standalone quality signal')
        ->and($first['methodology']['workflow_mutation'])
        ->toContain('does not write')
        ->and($task->refresh()->getRawOriginal('status'))
        ->toBe($taskStatusBefore)
        ->and(Review::query()->whereBelongsTo($task)->count())
        ->toBe($reviewCountBefore)
        ->and(AuditEvent::query()->whereBelongsTo($task)->count())
        ->toBe($auditCountBefore)
        ->and($run->refresh()->configuration_snapshot)
        ->toBe($snapshotBefore)
        ->and($review->refresh()->getRawOriginal('status'))
        ->toBe(ReviewStatus::ChangesRequired->value);
});
