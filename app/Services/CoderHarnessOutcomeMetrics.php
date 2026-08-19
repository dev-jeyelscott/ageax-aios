<?php

namespace App\Services;

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Review;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ReviewStatus;
use App\TaskStatus;
use DateTimeInterface;

class CoderHarnessOutcomeMetrics
{
    public const int SchemaVersion = 1;

    public const string NormalizationMethod = 'reference_median_ratio_capped_0_1';

    private const float FirstPassReviewerApprovalPoints = 35.0;

    private const float FirstPassValidationPoints = 20.0;

    private const float NoOperationalRetryOrBlockPoints = 15.0;

    private const float NoNoProgressRetryPoints = 10.0;

    private const float CostEfficiencyPoints = 15.0;

    private const float SpeedPoints = 5.0;

    private const array TerminalTaskStatuses = [
        TaskStatus::Done->value,
        TaskStatus::Failed->value,
        TaskStatus::Blocked->value,
    ];

    /**
     * Calculate Coder harness metrics for a caller-supplied comparable Task cohort.
     *
     * Cohort selection/fallback policy intentionally remains outside this service so
     * P3-020 can own comparable-cohort construction without duplicating metrics.
     *
     * @param  iterable<int, Task>  $tasks
     * @return array<string, mixed>
     */
    public function calculate(iterable $tasks): array
    {
        $outcomes = [];

        foreach ($tasks as $task) {
            if (! in_array($task->status->value, self::TerminalTaskStatuses, true)) {
                continue;
            }

            $outcomes[] = $this->taskOutcome($task);
        }

        return $this->scoreOutcomes($outcomes);
    }

    /**
     * Score already-derived Task outcomes.
     *
     * This public pure calculation boundary keeps normalization independently
     * unit-testable and reusable by the later comparable-cohort scorecard layer.
     *
     * @param  list<array<string, mixed>>  $outcomes
     * @return array<string, mixed>
     */
    public function scoreOutcomes(array $outcomes): array
    {
        $outcomes = array_values(array_filter(
            $outcomes,
            fn (array $outcome): bool => in_array(
                (string) ($outcome['task_status'] ?? ''),
                self::TerminalTaskStatuses,
                true,
            ),
        ));

        usort(
            $outcomes,
            fn (array $left, array $right): int => ((int) ($left['task_id'] ?? 0))
                <=> ((int) ($right['task_id'] ?? 0)),
        );

        $attributedOutcomes = array_values(array_filter(
            $outcomes,
            fn (array $outcome): bool => $this->isScorableAttributedOutcome($outcome),
        ));

        $unattributedOutcomes = array_values(array_filter(
            $outcomes,
            fn (array $outcome): bool => ! $this->isScorableAttributedOutcome($outcome),
        ));

        $successfulOutcomes = array_values(array_filter(
            $attributedOutcomes,
            fn (array $outcome): bool => ($outcome['task_status'] ?? null) === TaskStatus::Done->value,
        ));

        $tokenReference = $this->median($this->completeMetricValues(
            $successfulOutcomes,
            'total_token_usage',
        ));

        $durationReference = $this->median($this->completeMetricValues(
            $successfulOutcomes,
            'total_execution_duration_seconds',
        ));

        $groups = [];

        foreach ($attributedOutcomes as $outcome) {
            $configurationKey = (string) $outcome['configuration_key'];
            $groups[$configurationKey][] = $outcome;
        }

        ksort($groups);

        $configurationScores = [];

        foreach ($groups as $configurationKey => $groupOutcomes) {
            $configurationScores[] = $this->scoreConfiguration(
                $configurationKey,
                $groupOutcomes,
                $tokenReference,
                $durationReference,
            );
        }

        return [
            'schema_version' => self::SchemaVersion,
            'methodology' => [
                'role' => AgentRole::Coder->value,
                'terminal_task_statuses' => self::TerminalTaskStatuses,
                'successful_task_status' => TaskStatus::Done->value,
                'weights' => [
                    'quality' => [
                        'total' => 55.0,
                        'first_pass_reviewer_approval' => self::FirstPassReviewerApprovalPoints,
                        'first_pass_deterministic_validation' => self::FirstPassValidationPoints,
                    ],
                    'reliability' => [
                        'total' => 25.0,
                        'no_operational_retry_or_block' => self::NoOperationalRetryOrBlockPoints,
                        'no_no_progress_retry_condition' => self::NoNoProgressRetryPoints,
                    ],
                    'cost_efficiency' => self::CostEfficiencyPoints,
                    'speed' => self::SpeedPoints,
                ],
                'first_pass_definition' => 'First TaskAttempt backed by a persisted Coder AgentRun.',
                'reviewer_approval_definition' => 'First persisted valid Reviewer decision must approve the first Coder-backed TaskAttempt. Reviewer operational failures create no Coder quality penalty.',
                'operational_retry_or_block_definition' => 'Coder AgentRun failure/interruption, Coder no-progress/retry-exhaustion evidence, or a blocked Task after Coder execution.',
                'failed_blocked_policy' => 'Attributable failed and blocked Tasks remain in quality/reliability denominators. Outcomes without exact immutable Coder run attribution remain explicitly unattributed instead of being assigned from mutable Agent configuration.',
                'cost_speed_policy' => 'All Coder runs for an attributable successfully completed Task contribute token and duration cost, including failed/interrupted retries.',
                'missing_telemetry_policy' => 'Missing token or duration telemetry receives zero cost/speed contribution and is never treated as zero consumption.',
                'normalization' => [
                    'method' => self::NormalizationMethod,
                    'reference' => 'Median across successfully completed attributable Tasks in the supplied comparable cohort.',
                    'factor' => 'min(1, reference_median / task_value), bounded to 0..1. Zero-versus-zero scores 1; positive value against zero reference scores 0.',
                    'outlier_policy' => 'Normalization is capped to 0..1 so low-cost/fast outliers cannot exceed the component weight and high-cost/slow outliers cannot produce negative or unbounded scores.',
                ],
                'attribution' => 'Exact immutable AgentRun configuration_snapshot.agent harness/model/reasoning_setting only. Current mutable Agent configuration is never consulted.',
            ],
            'cohort' => [
                'terminal_task_count' => count($outcomes),
                'attributed_task_count' => count($attributedOutcomes),
                'unattributed_task_count' => count($unattributedOutcomes),
            ],
            'reference' => [
                'successful_attributed_task_count' => count($successfulOutcomes),
                'token_median' => $tokenReference,
                'duration_median_seconds' => $durationReference,
            ],
            'configuration_scores' => $configurationScores,
            'unattributed_outcomes' => $unattributedOutcomes,
            'task_outcomes' => $outcomes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskOutcome(Task $task): array
    {
        $task->loadMissing([
            'attempts',
            'reviews',
            'runs',
            'auditEvents',
        ]);

        $attempts = $task->attempts
            ->sortBy(fn (TaskAttempt $attempt): int => (int) $attempt->getAttribute('number'))
            ->values();

        $coderRuns = $task->runs
            ->filter(fn (AgentRun $run): bool => $run->role === AgentRole::Coder)
            ->sortBy(function (AgentRun $run): string {
                $attemptNumber = $this->integerValue($run->getAttribute('attempt_number'))
                    ?? PHP_INT_MAX;

                return sprintf(
                    '%020d:%020d',
                    $attemptNumber,
                    (int) $run->getKey(),
                );
            })
            ->values();

        $attemptsByNumber = [];

        foreach ($attempts as $attempt) {
            $attemptNumber = $this->integerValue($attempt->getAttribute('number'));

            if ($attemptNumber !== null) {
                $attemptsByNumber[$attemptNumber] = $attempt;
            }
        }

        /** @var TaskAttempt|null $firstCoderAttempt */
        $firstCoderAttempt = null;
        $incompleteAttemptEvidence = false;
        $knownTokenUsage = 0;
        $tokenUsageComplete = $coderRuns->isNotEmpty();
        $knownDurationSeconds = 0;
        $durationComplete = $coderRuns->isNotEmpty();
        $missingSnapshot = false;

        /** @var array<string, array{harness: string, model: ?string, reasoning_setting: ?string}> $configurations */
        $configurations = [];

        foreach ($coderRuns as $run) {
            $attemptNumber = $this->integerValue($run->getAttribute('attempt_number'));

            if ($attemptNumber === null || ! isset($attemptsByNumber[$attemptNumber])) {
                $incompleteAttemptEvidence = true;
            } elseif ($firstCoderAttempt === null) {
                $firstCoderAttempt = $attemptsByNumber[$attemptNumber];
            }

            $tokenUsage = $this->integerValue($run->getAttribute('token_usage'));

            if ($tokenUsage === null) {
                $tokenUsageComplete = false;
            } else {
                $knownTokenUsage += $tokenUsage;
            }

            if ($run->finished_at === null) {
                $durationComplete = false;
            } else {
                $knownDurationSeconds += max(
                    0,
                    $run->finished_at->getTimestamp() - $run->started_at->getTimestamp(),
                );
            }

            $configuration = $this->configurationFromRun($run);

            if ($configuration === null) {
                $missingSnapshot = true;

                continue;
            }

            $configurationKey = $this->configurationKey($configuration);
            $configurations[$configurationKey] = $configuration;
        }

        $configurationStatus = 'attributed';
        $configurationKey = null;
        $configuration = null;

        if ($coderRuns->isEmpty()) {
            $configurationStatus = 'missing_coder_run';
        } elseif ($missingSnapshot) {
            $configurationStatus = 'missing_snapshot';
        } elseif (count($configurations) > 1) {
            $configurationStatus = 'mixed_configuration';
        } elseif ($incompleteAttemptEvidence) {
            $configurationStatus = 'incomplete_attempt_evidence';
        } else {
            $configurationKey = array_key_first($configurations);

            if ($configurationKey === null) {
                $configurationStatus = 'missing_snapshot';
            } else {
                $configuration = $configurations[$configurationKey];
            }
        }

        $firstPassValidationPassed = false;

        if ($firstCoderAttempt !== null) {
            $validationResults = $firstCoderAttempt->getAttribute('validation_results');

            $firstPassValidationPassed = is_array($validationResults)
                && ($validationResults['passed'] ?? null) === true;
        }

        $validReviews = $task->reviews
            ->filter(function (Review $review): bool {
                return in_array(
                    (string) $review->getRawOriginal('status'),
                    [
                        ReviewStatus::Approved->value,
                        ReviewStatus::ChangesRequired->value,
                    ],
                    true,
                );
            })
            ->sortBy(function (Review $review): string {
                $completedAt = $review->getAttribute('completed_at');
                $timestamp = $completedAt instanceof DateTimeInterface
                    ? $completedAt->getTimestamp()
                    : PHP_INT_MAX;

                return sprintf(
                    '%020d:%020d',
                    $timestamp,
                    (int) $review->getKey(),
                );
            })
            ->values();

        /** @var Review|null $firstReview */
        $firstReview = $validReviews->first();

        $firstPassReviewerApproved = $firstCoderAttempt !== null
            && $firstReview !== null
            && (int) $firstReview->getAttribute('task_attempt_id') === (int) $firstCoderAttempt->getKey()
            && (string) $firstReview->getRawOriginal('status') === ReviewStatus::Approved->value;

        $noProgressRetryCondition = $attempts->contains(function (TaskAttempt $attempt): bool {
            $validationResults = $attempt->getAttribute('validation_results');

            if (! is_array($validationResults)) {
                return false;
            }

            $noProgress = $validationResults['no_progress'] ?? null;

            return is_array($noProgress)
                && ($noProgress['detected'] ?? null) === true;
        });

        $retryExhausted = false;

        foreach ($task->auditEvents as $auditEvent) {
            if (! $auditEvent instanceof AuditEvent) {
                continue;
            }

            $payload = $auditEvent->payload;
            $isCoderEvent = ($payload['operation'] ?? AgentRole::Coder->value) === AgentRole::Coder->value;

            if (
                $auditEvent->getAttribute('event_type') === 'task.no_progress_detected'
                && $isCoderEvent
            ) {
                $noProgressRetryCondition = true;
            }

            if (
                $auditEvent->getAttribute('event_type') === 'task.coder_retry_exhausted'
                && $isCoderEvent
            ) {
                $retryExhausted = true;
            }
        }

        $hasOperationalRunFailure = $coderRuns->contains(
            fn (AgentRun $run): bool => in_array(
                $run->status,
                [
                    AgentRunStatus::Failed,
                    AgentRunStatus::Interrupted,
                ],
                true,
            ),
        );

        $operationalRetryOrBlock = $hasOperationalRunFailure
            || $retryExhausted
            || $noProgressRetryCondition
            || ($task->status === TaskStatus::Blocked && $coderRuns->isNotEmpty());

        return [
            'task_id' => (int) $task->getKey(),
            'task_key' => (string) $task->getAttribute('key'),
            'project_id' => (int) $task->getAttribute('project_id'),
            'work_type' => $task->work_type?->value,
            'complexity' => $task->complexity?->value,
            'task_status' => $task->status->value,
            'configuration_status' => $configurationStatus,
            'configuration_key' => $configurationKey,
            'configuration' => $configuration,
            'observed_configurations' => array_values($configurations),
            'first_pass_reviewer_approved' => $firstPassReviewerApproved,
            'first_pass_validation_passed' => $firstPassValidationPassed,
            'operational_retry_or_block' => $operationalRetryOrBlock,
            'no_progress_retry_condition' => $noProgressRetryCondition,
            'coder_run_count' => $coderRuns->count(),
            'attempt_count' => $attempts->count(),
            'review_count' => $validReviews->count(),
            'known_token_usage' => $knownTokenUsage,
            'token_usage_complete' => $tokenUsageComplete,
            'total_token_usage' => $tokenUsageComplete ? $knownTokenUsage : null,
            'known_execution_duration_seconds' => $knownDurationSeconds,
            'duration_complete' => $durationComplete,
            'total_execution_duration_seconds' => $durationComplete
                ? $knownDurationSeconds
                : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     * @return array<string, mixed>
     */
    private function scoreConfiguration(
        string $configurationKey,
        array $outcomes,
        ?float $tokenReference,
        ?float $durationReference,
    ): array {
        $sampleCount = count($outcomes);

        $successfulOutcomes = array_values(array_filter(
            $outcomes,
            fn (array $outcome): bool => ($outcome['task_status'] ?? null) === TaskStatus::Done->value,
        ));

        $firstPassReviewerApprovalRate = $this->rate(
            $outcomes,
            fn (array $outcome): bool => ($outcome['first_pass_reviewer_approved'] ?? false) === true,
        );

        $firstPassValidationRate = $this->rate(
            $outcomes,
            fn (array $outcome): bool => ($outcome['first_pass_validation_passed'] ?? false) === true,
        );

        $noOperationalRetryOrBlockRate = $this->rate(
            $outcomes,
            fn (array $outcome): bool => ($outcome['operational_retry_or_block'] ?? true) === false,
        );

        $noNoProgressRetryConditionRate = $this->rate(
            $outcomes,
            fn (array $outcome): bool => ($outcome['no_progress_retry_condition'] ?? true) === false,
        );

        $costFactors = [];
        $speedFactors = [];

        foreach ($successfulOutcomes as $outcome) {
            $costFactors[] = $this->normalizationFactor(
                $this->numericMetric($outcome['total_token_usage'] ?? null),
                $tokenReference,
            );

            $speedFactors[] = $this->normalizationFactor(
                $this->numericMetric($outcome['total_execution_duration_seconds'] ?? null),
                $durationReference,
            );
        }

        $costEfficiencyRate = $this->average($costFactors);
        $speedRate = $this->average($speedFactors);

        $firstPassReviewerApprovalPoints = self::FirstPassReviewerApprovalPoints
            * $firstPassReviewerApprovalRate;

        $firstPassValidationPoints = self::FirstPassValidationPoints
            * $firstPassValidationRate;

        $noOperationalRetryOrBlockPoints = self::NoOperationalRetryOrBlockPoints
            * $noOperationalRetryOrBlockRate;

        $noNoProgressRetryPoints = self::NoNoProgressRetryPoints
            * $noNoProgressRetryConditionRate;

        $costEfficiencyPoints = self::CostEfficiencyPoints * $costEfficiencyRate;
        $speedPoints = self::SpeedPoints * $speedRate;

        $qualityPoints = $firstPassReviewerApprovalPoints + $firstPassValidationPoints;
        $reliabilityPoints = $noOperationalRetryOrBlockPoints + $noNoProgressRetryPoints;

        $compositeScore = $qualityPoints
            + $reliabilityPoints
            + $costEfficiencyPoints
            + $speedPoints;

        $configuration = $outcomes[0]['configuration'] ?? null;

        return [
            'configuration_key' => $configurationKey,
            'configuration' => is_array($configuration) ? $configuration : null,
            'sample_count' => $sampleCount,
            'successful_task_count' => count($successfulOutcomes),
            'failed_task_count' => $this->statusCount($outcomes, TaskStatus::Failed),
            'blocked_task_count' => $this->statusCount($outcomes, TaskStatus::Blocked),
            'rates' => [
                'first_pass_reviewer_approval' => round($firstPassReviewerApprovalRate, 6),
                'first_pass_deterministic_validation' => round($firstPassValidationRate, 6),
                'no_operational_retry_or_block' => round($noOperationalRetryOrBlockRate, 6),
                'no_no_progress_retry_condition' => round($noNoProgressRetryConditionRate, 6),
                'cost_efficiency' => round($costEfficiencyRate, 6),
                'speed' => round($speedRate, 6),
            ],
            'medians' => [
                'token_usage' => $this->median($this->completeMetricValues(
                    $successfulOutcomes,
                    'total_token_usage',
                )),
                'execution_duration_seconds' => $this->median($this->completeMetricValues(
                    $successfulOutcomes,
                    'total_execution_duration_seconds',
                )),
            ],
            'component_points' => [
                'quality' => [
                    'first_pass_reviewer_approval' => round($firstPassReviewerApprovalPoints, 4),
                    'first_pass_deterministic_validation' => round($firstPassValidationPoints, 4),
                    'total' => round($qualityPoints, 4),
                ],
                'reliability' => [
                    'no_operational_retry_or_block' => round($noOperationalRetryOrBlockPoints, 4),
                    'no_no_progress_retry_condition' => round($noNoProgressRetryPoints, 4),
                    'total' => round($reliabilityPoints, 4),
                ],
                'cost_efficiency' => round($costEfficiencyPoints, 4),
                'speed' => round($speedPoints, 4),
            ],
            'composite_score' => round($compositeScore, 4),
        ];
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function isScorableAttributedOutcome(array $outcome): bool
    {
        return ($outcome['configuration_status'] ?? null) === 'attributed'
            && is_string($outcome['configuration_key'] ?? null)
            && $outcome['configuration_key'] !== ''
            && is_array($outcome['configuration'] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     * @param  callable(array<string, mixed>): bool  $predicate
     */
    private function rate(array $outcomes, callable $predicate): float
    {
        if ($outcomes === []) {
            return 0.0;
        }

        return count(array_filter($outcomes, $predicate)) / count($outcomes);
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     */
    private function statusCount(array $outcomes, TaskStatus $status): int
    {
        return count(array_filter(
            $outcomes,
            fn (array $outcome): bool => ($outcome['task_status'] ?? null) === $status->value,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     * @return list<float>
     */
    private function completeMetricValues(array $outcomes, string $field): array
    {
        $values = [];

        foreach ($outcomes as $outcome) {
            $value = $this->numericMetric($outcome[$field] ?? null);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Median is deliberately used instead of mean because execution token/time data
     * is expected to be positively skewed by retries and pathological runs.
     *
     * @param  list<float>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);

        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    /**
     * Lower token/time consumption is better.
     */
    private function normalizationFactor(?float $value, ?float $referenceMedian): float
    {
        if ($value === null || $referenceMedian === null) {
            return 0.0;
        }

        if ($referenceMedian <= 0.0) {
            return $value <= 0.0 ? 1.0 : 0.0;
        }

        if ($value <= 0.0) {
            return 1.0;
        }

        return max(
            0.0,
            min(1.0, $referenceMedian / $value),
        );
    }

    /**
     * @param  list<float>  $values
     */
    private function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    private function numericMetric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array{harness: string, model: ?string, reasoning_setting: ?string}|null
     */
    private function configurationFromRun(AgentRun $run): ?array
    {
        $snapshot = $run->configuration_snapshot;

        if (! is_array($snapshot)) {
            return null;
        }

        $agent = $snapshot['agent'] ?? null;

        if (! is_array($agent)) {
            return null;
        }

        $harness = $agent['harness'] ?? null;
        $model = $agent['model'] ?? null;
        $reasoningSetting = $agent['reasoning_setting'] ?? null;

        if (! is_string($harness) || $harness === '') {
            return null;
        }

        if ($model !== null && ! is_string($model)) {
            return null;
        }

        if ($reasoningSetting !== null && ! is_string($reasoningSetting)) {
            return null;
        }

        if ($run->harness === null || $run->harness !== $harness) {
            return null;
        }

        return [
            'harness' => $harness,
            'model' => $model,
            'reasoning_setting' => $reasoningSetting,
        ];
    }

    /**
     * @param  array{harness: string, model: ?string, reasoning_setting: ?string}  $configuration
     */
    private function configurationKey(array $configuration): string
    {
        return hash('sha256', json_encode([
            'harness' => $configuration['harness'],
            'model' => $configuration['model'],
            'reasoning_setting' => $configuration['reasoning_setting'],
        ], JSON_THROW_ON_ERROR));
    }
}
