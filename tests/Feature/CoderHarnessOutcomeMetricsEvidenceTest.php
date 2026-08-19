<?php

use App\AgentHarness;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\ReviewStatus;
use App\Services\CoderHarnessOutcomeMetrics;
use App\TaskComplexity;
use App\TaskStatus;
use App\TaskWorkType;
use Illuminate\Support\Str;

function p3CoderMetricProject(): Project
{
    return Project::factory()->create([
        'name' => 'P3 Coder Metrics '.Str::uuid(),
        'path' => sys_get_temp_dir().'/ageax-p3-coder-metrics-'.Str::uuid(),
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
    ]);
}

function p3CoderMetricTask(
    Project $project,
    int $position,
    TaskStatus $status = TaskStatus::Done,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'phase_id' => null,
        'key' => 'TASK-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT),
        'position' => $position,
        'title' => "Coder metric task {$position}",
        'objective' => 'Provide persisted deterministic scorecard evidence.',
        'work_type' => TaskWorkType::Feature,
        'complexity' => TaskComplexity::Medium,
        'acceptance_criteria' => ['Persisted evidence is scored deterministically.'],
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

function p3CoderMetricAttempt(
    Task $task,
    int $number,
    string $status,
    bool $validationPassed,
    bool $noProgress = false,
): TaskAttempt {
    $validationResults = [
        'passed' => $validationPassed,
        'checks' => [
            'tests' => $validationPassed,
        ],
    ];

    if ($noProgress) {
        $validationResults['no_progress'] = [
            'eligible' => true,
            'failure_fingerprint' => hash('sha256', "task:{$task->id}:attempt:{$number}"),
            'consecutive_identical_failures' => 2,
            'consecutive_repeat_count' => 2,
            'threshold' => 2,
            'detected' => true,
            'repository_fingerprint' => hash('sha256', "repo:{$task->id}"),
        ];
    }

    return TaskAttempt::create([
        'task_id' => $task->id,
        'number' => $number,
        'base_sha' => str_repeat('a', 40),
        'head_sha' => str_repeat('b', 40),
        'status' => $status,
        'validation_results' => $validationResults,
        'changed_files' => [],
        'started_at' => now()->subSeconds(5),
        'finished_at' => now(),
    ]);
}

function p3CoderMetricRun(
    Task $task,
    int $attemptNumber,
    string $harness,
    string $model,
    ?string $reasoningSetting,
    AgentRunStatus $status = AgentRunStatus::Completed,
    ?int $tokenUsage = 100,
    ?int $durationSeconds = 10,
    ?Agent $agent = null,
): AgentRun {
    $finishedAt = $durationSeconds === null ? null : now();
    $startedAt = $finishedAt?->copy()->subSeconds($durationSeconds) ?? now()->subSeconds(10);

    return AgentRun::create([
        'project_id' => $task->project_id,
        'task_id' => $task->id,
        'agent_id' => $agent?->id,
        'role' => AgentRole::Coder,
        'harness' => $harness,
        'status' => $status,
        'attempt_number' => $attemptNumber,
        'prompt_hash' => hash(
            'sha256',
            "{$task->id}:{$attemptNumber}:{$harness}:".Str::uuid(),
        ),
        'configuration_snapshot' => [
            'agent' => [
                'id' => $agent?->id,
                'name' => $agent?->name ?? 'Historical Coder',
                'role' => AgentRole::Coder->value,
                'harness' => $harness,
                'model' => $model,
                'reasoning_setting' => $reasoningSetting,
                'default_context' => null,
                'configuration_version' => $agent?->configuration_version ?? 1,
            ],
        ],
        'context_schema_version' => 2,
        'token_usage' => $tokenUsage,
        'exit_code' => $status === AgentRunStatus::Completed ? 0 : 1,
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
    ]);
}

function p3CoderMetricReview(
    Task $task,
    TaskAttempt $attempt,
    ReviewStatus $status,
): Review {
    return Review::create([
        'task_id' => $task->id,
        'task_attempt_id' => $attempt->id,
        'status' => $status,
        'summary' => $status === ReviewStatus::Approved
            ? 'Implementation approved.'
            : 'Changes are required.',
        'started_at' => now()->subSecond(),
        'completed_at' => now(),
    ]);
}

test('rejected then approved work keeps first-pass quality evidence and all retry token and duration cost', function () {
    $project = p3CoderMetricProject();

    $agent = Agent::create([
        'project_id' => $project->id,
        'name' => 'Historical Coder',
        'role' => AgentRole::Coder,
        'harness' => AgentHarness::Codex,
        'model' => 'gpt-5',
        'reasoning_setting' => 'high',
        'default_context' => null,
        'enabled' => true,
    ]);

    $task = p3CoderMetricTask($project, 1);

    $attemptOne = p3CoderMetricAttempt(
        $task,
        number: 1,
        status: 'completed',
        validationPassed: true,
    );

    p3CoderMetricRun(
        $task,
        attemptNumber: 1,
        harness: AgentHarness::Codex->value,
        model: 'gpt-5',
        reasoningSetting: 'high',
        tokenUsage: 100,
        durationSeconds: 10,
        agent: $agent,
    );

    p3CoderMetricReview(
        $task,
        $attemptOne,
        ReviewStatus::ChangesRequired,
    );

    $attemptTwo = p3CoderMetricAttempt(
        $task,
        number: 2,
        status: 'completed',
        validationPassed: true,
    );

    p3CoderMetricRun(
        $task,
        attemptNumber: 2,
        harness: AgentHarness::Codex->value,
        model: 'gpt-5',
        reasoningSetting: 'high',
        tokenUsage: 150,
        durationSeconds: 20,
        agent: $agent,
    );

    p3CoderMetricReview(
        $task,
        $attemptTwo,
        ReviewStatus::Approved,
    );

    $agent->update([
        'harness' => AgentHarness::ClaudeCode,
        'model' => 'claude-sonnet',
        'reasoning_setting' => null,
    ]);

    $result = app(CoderHarnessOutcomeMetrics::class)->calculate([$task->refresh()]);
    $outcome = $result['task_outcomes'][0];
    $score = $result['configuration_scores'][0];

    expect($outcome['configuration_status'])
        ->toBe('attributed')
        ->and($outcome['configuration']['harness'])
        ->toBe(AgentHarness::Codex->value)
        ->and($outcome['configuration']['model'])
        ->toBe('gpt-5')
        ->and($outcome['configuration']['reasoning_setting'])
        ->toBe('high')
        ->and($outcome['first_pass_reviewer_approved'])
        ->toBeFalse()
        ->and($outcome['first_pass_validation_passed'])
        ->toBeTrue()
        ->and($outcome['operational_retry_or_block'])
        ->toBeFalse()
        ->and($outcome['total_token_usage'])
        ->toBe(250)
        ->and($outcome['total_execution_duration_seconds'])
        ->toBe(30)
        ->and($score['component_points']['quality']['first_pass_reviewer_approval'])
        ->toBe(0.0)
        ->and($score['component_points']['quality']['first_pass_deterministic_validation'])
        ->toBe(20.0)
        ->and($score['composite_score'])
        ->toBe(65.0);
});

test('operational Coder retries are reliability failures and retain failed run cost', function () {
    $project = p3CoderMetricProject();
    $task = p3CoderMetricTask($project, 1);

    p3CoderMetricAttempt(
        $task,
        number: 1,
        status: 'failed',
        validationPassed: false,
    );

    p3CoderMetricRun(
        $task,
        attemptNumber: 1,
        harness: AgentHarness::Codex->value,
        model: 'gpt-5',
        reasoningSetting: 'high',
        status: AgentRunStatus::Failed,
        tokenUsage: 40,
        durationSeconds: 4,
    );

    $attemptTwo = p3CoderMetricAttempt(
        $task,
        number: 2,
        status: 'completed',
        validationPassed: true,
    );

    p3CoderMetricRun(
        $task,
        attemptNumber: 2,
        harness: AgentHarness::Codex->value,
        model: 'gpt-5',
        reasoningSetting: 'high',
        tokenUsage: 60,
        durationSeconds: 6,
    );

    p3CoderMetricReview(
        $task,
        $attemptTwo,
        ReviewStatus::Approved,
    );

    $result = app(CoderHarnessOutcomeMetrics::class)->calculate([$task->refresh()]);
    $outcome = $result['task_outcomes'][0];

    expect($outcome['first_pass_reviewer_approved'])
        ->toBeFalse()
        ->and($outcome['first_pass_validation_passed'])
        ->toBeFalse()
        ->and($outcome['operational_retry_or_block'])
        ->toBeTrue()
        ->and($outcome['no_progress_retry_condition'])
        ->toBeFalse()
        ->and($outcome['total_token_usage'])
        ->toBe(100)
        ->and($outcome['total_execution_duration_seconds'])
        ->toBe(10)
        ->and($result['configuration_scores'][0]['rates']['no_operational_retry_or_block'])
        ->toBe(0.0);
});

test('failed and blocked Tasks remain in quality and reliability denominators', function () {
    $project = p3CoderMetricProject();

    $doneTask = p3CoderMetricTask($project, 1, TaskStatus::Done);
    $doneAttempt = p3CoderMetricAttempt(
        $doneTask,
        number: 1,
        status: 'completed',
        validationPassed: true,
    );

    p3CoderMetricRun(
        $doneTask,
        attemptNumber: 1,
        harness: AgentHarness::Codex->value,
        model: 'gpt-5',
        reasoningSetting: 'high',
    );

    p3CoderMetricReview(
        $doneTask,
        $doneAttempt,
        ReviewStatus::Approved,
    );

    $failedTask = p3CoderMetricTask($project, 2, TaskStatus::Failed);

    p3CoderMetricAttempt(
        $failedTask,
        number: 1,
        status: 'failed',
        validationPassed: false,
    );

    p3CoderMetricRun(
        $failedTask,
        attemptNumber: 1,
        harness: AgentHarness::Codex->value,
        model: 'gpt-5',
        reasoningSetting: 'high',
    );

    $blockedTask = p3CoderMetricTask($project, 3, TaskStatus::Blocked);

    p3CoderMetricAttempt(
        $blockedTask,
        number: 1,
        status: 'failed',
        validationPassed: false,
    );

    p3CoderMetricRun(
        $blockedTask,
        attemptNumber: 1,
        harness: AgentHarness::Codex->value,
        model: 'gpt-5',
        reasoningSetting: 'high',
    );

    AuditEvent::create([
        'project_id' => $project->id,
        'task_id' => $blockedTask->id,
        'event_type' => 'task.no_progress_detected',
        'payload' => [
            'operation' => AgentRole::Coder->value,
            'attempt_number' => 1,
        ],
        'occurred_at' => now(),
    ]);

    $result = app(CoderHarnessOutcomeMetrics::class)->calculate([
        $doneTask->refresh(),
        $failedTask->refresh(),
        $blockedTask->refresh(),
    ]);

    $score = $result['configuration_scores'][0];

    expect($score['sample_count'])
        ->toBe(3)
        ->and($score['successful_task_count'])
        ->toBe(1)
        ->and($score['failed_task_count'])
        ->toBe(1)
        ->and($score['blocked_task_count'])
        ->toBe(1)
        ->and($score['rates']['first_pass_reviewer_approval'])
        ->toBe(0.333333)
        ->and($score['rates']['first_pass_deterministic_validation'])
        ->toBe(0.333333)
        ->and($score['rates']['no_operational_retry_or_block'])
        ->toBe(0.666667)
        ->and($score['rates']['no_no_progress_retry_condition'])
        ->toBe(0.666667);
});

test('pre-provider blocks and mixed historical configurations stay visible without guessed harness attribution', function () {
    $project = p3CoderMetricProject();

    $preProviderBlockedTask = p3CoderMetricTask(
        $project,
        1,
        TaskStatus::Blocked,
    );

    p3CoderMetricAttempt(
        $preProviderBlockedTask,
        number: 1,
        status: 'blocked',
        validationPassed: false,
    );

    $mixedTask = p3CoderMetricTask($project, 2, TaskStatus::Done);

    $mixedAttemptOne = p3CoderMetricAttempt(
        $mixedTask,
        number: 1,
        status: 'completed',
        validationPassed: true,
    );

    p3CoderMetricRun(
        $mixedTask,
        attemptNumber: 1,
        harness: AgentHarness::Codex->value,
        model: 'gpt-5',
        reasoningSetting: 'high',
    );

    p3CoderMetricReview(
        $mixedTask,
        $mixedAttemptOne,
        ReviewStatus::ChangesRequired,
    );

    $mixedAttemptTwo = p3CoderMetricAttempt(
        $mixedTask,
        number: 2,
        status: 'completed',
        validationPassed: true,
    );

    p3CoderMetricRun(
        $mixedTask,
        attemptNumber: 2,
        harness: AgentHarness::ClaudeCode->value,
        model: 'claude-sonnet',
        reasoningSetting: 'high',
    );

    p3CoderMetricReview(
        $mixedTask,
        $mixedAttemptTwo,
        ReviewStatus::Approved,
    );

    $result = app(CoderHarnessOutcomeMetrics::class)->calculate([
        $preProviderBlockedTask->refresh(),
        $mixedTask->refresh(),
    ]);

    $outcomes = collect($result['task_outcomes'])->keyBy('task_id');

    expect($result['cohort']['terminal_task_count'])
        ->toBe(2)
        ->and($result['cohort']['attributed_task_count'])
        ->toBe(0)
        ->and($result['cohort']['unattributed_task_count'])
        ->toBe(2)
        ->and($result['configuration_scores'])
        ->toBe([])
        ->and($outcomes[$preProviderBlockedTask->id]['configuration_status'])
        ->toBe('missing_coder_run')
        ->and($outcomes[$mixedTask->id]['configuration_status'])
        ->toBe('mixed_configuration')
        ->and($outcomes[$mixedTask->id]['observed_configurations'])
        ->toHaveCount(2);
});
