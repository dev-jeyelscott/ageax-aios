<?php

use App\Services\CoderHarnessOutcomeMetrics;

function coderMetricOutcome(
    int $taskId,
    string $configurationKey,
    string $harness,
    string $status = 'done',
    bool $firstPassReviewerApproved = true,
    bool $firstPassValidationPassed = true,
    bool $operationalRetryOrBlock = false,
    bool $noProgressRetryCondition = false,
    ?int $tokenUsage = 100,
    ?int $durationSeconds = 10,
): array {
    return [
        'task_id' => $taskId,
        'task_key' => 'TASK-'.str_pad((string) $taskId, 3, '0', STR_PAD_LEFT),
        'project_id' => 1,
        'work_type' => 'feature',
        'complexity' => 'medium',
        'task_status' => $status,
        'configuration_status' => 'attributed',
        'configuration_key' => $configurationKey,
        'configuration' => [
            'harness' => $harness,
            'model' => $harness === 'codex' ? 'gpt-5' : 'claude-sonnet',
            'reasoning_setting' => 'high',
        ],
        'observed_configurations' => [],
        'first_pass_reviewer_approved' => $firstPassReviewerApproved,
        'first_pass_validation_passed' => $firstPassValidationPassed,
        'operational_retry_or_block' => $operationalRetryOrBlock,
        'no_progress_retry_condition' => $noProgressRetryCondition,
        'coder_run_count' => 1,
        'attempt_count' => 1,
        'review_count' => $firstPassReviewerApproved ? 1 : 0,
        'known_token_usage' => $tokenUsage ?? 0,
        'token_usage_complete' => $tokenUsage !== null,
        'total_token_usage' => $tokenUsage,
        'known_execution_duration_seconds' => $durationSeconds ?? 0,
        'duration_complete' => $durationSeconds !== null,
        'total_execution_duration_seconds' => $durationSeconds,
    ];
}

test('methodology version and component weights are explicit and a perfect outcome scores one hundred', function () {
    $result = (new CoderHarnessOutcomeMetrics)->scoreOutcomes([
        coderMetricOutcome(
            taskId: 1,
            configurationKey: 'codex',
            harness: 'codex',
        ),
    ]);

    expect($result['schema_version'])
        ->toBe(CoderHarnessOutcomeMetrics::SchemaVersion)
        ->and($result['methodology']['weights']['quality']['first_pass_reviewer_approval'])
        ->toBe(35.0)
        ->and($result['methodology']['weights']['quality']['first_pass_deterministic_validation'])
        ->toBe(20.0)
        ->and($result['methodology']['weights']['reliability']['no_operational_retry_or_block'])
        ->toBe(15.0)
        ->and($result['methodology']['weights']['reliability']['no_no_progress_retry_condition'])
        ->toBe(10.0)
        ->and($result['methodology']['weights']['cost_efficiency'])
        ->toBe(15.0)
        ->and($result['methodology']['weights']['speed'])
        ->toBe(5.0)
        ->and($result['configuration_scores'][0]['composite_score'])
        ->toBe(100.0);
});

test('cost and speed use cohort median normalization with deterministic outlier caps', function () {
    $result = (new CoderHarnessOutcomeMetrics)->scoreOutcomes([
        coderMetricOutcome(
            taskId: 1,
            configurationKey: 'codex',
            harness: 'codex',
            tokenUsage: 100,
            durationSeconds: 10,
        ),
        coderMetricOutcome(
            taskId: 2,
            configurationKey: 'claude',
            harness: 'claude_code',
            tokenUsage: 300,
            durationSeconds: 30,
        ),
        coderMetricOutcome(
            taskId: 3,
            configurationKey: 'claude',
            harness: 'claude_code',
            tokenUsage: 10_000,
            durationSeconds: 1_000,
        ),
    ]);

    $scores = array_column(
        $result['configuration_scores'],
        null,
        'configuration_key',
    );

    expect($result['reference']['token_median'])
        ->toBe(300.0)
        ->and($result['reference']['duration_median_seconds'])
        ->toBe(30.0)
        ->and($scores['codex']['rates']['cost_efficiency'])
        ->toBe(1.0)
        ->and($scores['codex']['rates']['speed'])
        ->toBe(1.0)
        ->and($scores['codex']['component_points']['cost_efficiency'])
        ->toBe(15.0)
        ->and($scores['codex']['component_points']['speed'])
        ->toBe(5.0)
        ->and($scores['claude']['rates']['cost_efficiency'])
        ->toBe(0.515)
        ->and($scores['claude']['rates']['speed'])
        ->toBe(0.515)
        ->and($scores['claude']['component_points']['cost_efficiency'])
        ->toBe(7.725)
        ->and($scores['claude']['component_points']['speed'])
        ->toBe(2.575)
        ->and($scores['claude']['composite_score'])
        ->toBeLessThanOrEqual(100.0);
});

test('zero equal and empty successful cohorts have deterministic bounded behavior', function () {
    $service = new CoderHarnessOutcomeMetrics;

    $zeroResult = $service->scoreOutcomes([
        coderMetricOutcome(
            taskId: 1,
            configurationKey: 'codex',
            harness: 'codex',
            tokenUsage: 0,
            durationSeconds: 0,
        ),
        coderMetricOutcome(
            taskId: 2,
            configurationKey: 'codex',
            harness: 'codex',
            tokenUsage: 0,
            durationSeconds: 0,
        ),
    ]);

    expect($zeroResult['reference']['token_median'])
        ->toBe(0.0)
        ->and($zeroResult['reference']['duration_median_seconds'])
        ->toBe(0.0)
        ->and($zeroResult['configuration_scores'][0]['rates']['cost_efficiency'])
        ->toBe(1.0)
        ->and($zeroResult['configuration_scores'][0]['rates']['speed'])
        ->toBe(1.0);

    $emptySuccessfulResult = $service->scoreOutcomes([
        coderMetricOutcome(
            taskId: 3,
            configurationKey: 'codex',
            harness: 'codex',
            status: 'failed',
            firstPassReviewerApproved: false,
            firstPassValidationPassed: false,
            tokenUsage: 250,
            durationSeconds: 25,
        ),
    ]);

    expect($emptySuccessfulResult['reference']['token_median'])
        ->toBeNull()
        ->and($emptySuccessfulResult['reference']['duration_median_seconds'])
        ->toBeNull()
        ->and($emptySuccessfulResult['configuration_scores'][0]['component_points']['cost_efficiency'])
        ->toBe(0.0)
        ->and($emptySuccessfulResult['configuration_scores'][0]['component_points']['speed'])
        ->toBe(0.0);
});

test('missing successful telemetry receives no efficiency reward', function () {
    $result = (new CoderHarnessOutcomeMetrics)->scoreOutcomes([
        coderMetricOutcome(
            taskId: 1,
            configurationKey: 'codex',
            harness: 'codex',
            tokenUsage: null,
            durationSeconds: null,
        ),
    ]);

    expect($result['reference']['token_median'])
        ->toBeNull()
        ->and($result['reference']['duration_median_seconds'])
        ->toBeNull()
        ->and($result['configuration_scores'][0]['component_points']['cost_efficiency'])
        ->toBe(0.0)
        ->and($result['configuration_scores'][0]['component_points']['speed'])
        ->toBe(0.0);
});
