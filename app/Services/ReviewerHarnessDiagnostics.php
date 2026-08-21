<?php

namespace App\Services;

use App\AgentHarness;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ReviewStatus;
use App\TaskComplexity;
use App\TaskWorkType;
use DateTimeInterface;

final class ReviewerHarnessDiagnostics
{
    public const int SchemaVersion = 1;

    public const string MethodologyVersion = 'reviewer_harness_diagnostics_v1';

    public const string FallbackPolicyVersion = CoderHarnessComparableCohortScorecards::FallbackPolicyVersion;

    private TokenUsageNormalizer $tokenUsage;

    public function __construct(?TokenUsageNormalizer $tokenUsage = null)
    {
        $this->tokenUsage = $tokenUsage ?? new TokenUsageNormalizer;
    }

    /**
     * Calculate observational Reviewer diagnostics for one project/repository boundary.
     *
     * P3-021 deliberately does not calculate a Reviewer quality composite or recommendation.
     * Raw approval and changes-required rates remain diagnostic evidence only.
     *
     * @param  iterable<int, Task>  $tasks
     * @return array<string, mixed>
     */
    public function calculate(
        Project $project,
        iterable $tasks,
        TaskWorkType $workType,
        TaskComplexity $complexity,
    ): array {
        $projectTasks = $this->uniqueProjectTasks($project, $tasks);
        $cycles = [];

        foreach ($projectTasks as $task) {
            array_push($cycles, ...$this->reviewCycles($task));
        }

        $candidates = [
            $this->evaluateCandidate(
                project: $project,
                cycles: $cycles,
                fallbackLevel: 0,
                fallbackKey: 'exact_project_work_type_complexity',
                workType: $workType,
                complexity: $complexity,
                broadenedDimensions: [],
            ),
            $this->evaluateCandidate(
                project: $project,
                cycles: $cycles,
                fallbackLevel: 1,
                fallbackKey: 'same_project_same_complexity_all_work_types',
                workType: null,
                complexity: $complexity,
                broadenedDimensions: ['work_type'],
            ),
            $this->evaluateCandidate(
                project: $project,
                cycles: $cycles,
                fallbackLevel: 2,
                fallbackKey: 'same_project_all_work_types_and_complexities',
                workType: null,
                complexity: null,
                broadenedDimensions: ['work_type', 'complexity'],
            ),
        ];

        $selected = $this->selectCandidate($candidates);
        $diagnostics = is_array($selected['diagnostics'] ?? null)
            ? $selected['diagnostics']
            : $this->summarizeCycles([]);

        return [
            'schema_version' => self::SchemaVersion,
            'methodology_version' => self::MethodologyVersion,
            'methodology' => $this->methodology(),
            'fallback_policy' => [
                'version' => self::FallbackPolicyVersion,
                'inherited_from' => CoderHarnessComparableCohortScorecards::class,
                'preserved_dimensions' => [
                    'workflow_role',
                    'project_repository',
                ],
                'configuration_dimensions' => [
                    'harness',
                    'model',
                    'reasoning_setting',
                ],
                'broadening_order' => [
                    'work_type',
                    'complexity',
                ],
                'order' => [
                    'exact_project_work_type_complexity',
                    'same_project_same_complexity_all_work_types',
                    'same_project_all_work_types_and_complexities',
                ],
                'selection_policy' => 'Use the most-specific candidate containing at least two attributed Reviewer configurations with valid decisions. If none is directly comparable, use the candidate with the most attributed valid decisions and break ties toward more configurations, then greater specificity.',
                'unknown_metadata_policy' => 'Historical null work_type/complexity is excluded from exact filters and participates only after that dimension is explicitly broadened.',
            ],
            'requested_cohort' => [
                'workflow_role' => AgentRole::Reviewer->value,
                'project_repository' => [
                    'project_id' => (int) $project->getKey(),
                    'project_name' => (string) $project->getAttribute('name'),
                ],
                'work_type' => $workType->value,
                'complexity' => $complexity->value,
            ],
            'selected_cohort' => $selected['cohort'],
            'fallback_evaluations' => array_map(
                fn (array $candidate): array => [
                    'fallback_level' => (int) $candidate['fallback_level'],
                    'fallback_key' => (string) $candidate['fallback_key'],
                    'cohort' => $candidate['cohort'],
                    'sample' => $candidate['sample'],
                    'directly_comparable' => (bool) $candidate['directly_comparable'],
                ],
                $candidates,
            ),
            'sample' => $diagnostics['sample'],
            'rates' => $diagnostics['rates'],
            'operational_failure_reasons' => $diagnostics['operational_failure_reasons'],
            'configuration_diagnostics' => $diagnostics['configuration_diagnostics'],
            'approval_consistency' => $diagnostics['approval_consistency'],
            'codex_claude_divergence' => $diagnostics['codex_claude_divergence'],
            'actionable_finding_follow_through' => $diagnostics['actionable_finding_follow_through'],
            'unattributed_cycles' => $diagnostics['unattributed_cycles'],
            'review_cycles' => $diagnostics['review_cycles'],
            'recommendation_policy' => [
                'mode' => 'diagnostic_only',
                'automatic_routing' => false,
                'quality_composite' => false,
                'ground_truth_policy' => 'No independent adjudication is invented. Outcome consistency/divergence is observational unless separate deterministic evidence exists.',
            ],
        ];
    }

    /**
     * @param  iterable<int, Task>  $tasks
     * @return list<Task>
     */
    private function uniqueProjectTasks(Project $project, iterable $tasks): array
    {
        /** @var array<int, Task> $unique */
        $unique = [];
        $projectId = (int) $project->getKey();

        foreach ($tasks as $task) {
            if ((int) $task->getAttribute('project_id') !== $projectId) {
                continue;
            }

            $taskId = (int) $task->getKey();

            if ($taskId < 1 || isset($unique[$taskId])) {
                continue;
            }

            $unique[$taskId] = $task;
        }

        ksort($unique, SORT_NUMERIC);

        return array_values($unique);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviewCycles(Task $task): array
    {
        $task->loadMissing([
            'attempts',
            'reviews.findings',
            'runs',
            'auditEvents',
        ]);

        /** @var array<int, TaskAttempt> $attemptsById */
        $attemptsById = [];
        /** @var array<int, TaskAttempt> $attemptsByNumber */
        $attemptsByNumber = [];

        foreach ($task->attempts as $attempt) {
            $number = $this->integerValue($attempt->getAttribute('number'));

            if ($number === null) {
                continue;
            }

            $attemptsById[(int) $attempt->getKey()] = $attempt;
            $attemptsByNumber[$number] = $attempt;
        }

        ksort($attemptsByNumber, SORT_NUMERIC);

        /** @var array<int, list<Review>> $reviewsByAttempt */
        $reviewsByAttempt = [];

        foreach ($task->reviews as $review) {
            $status = $this->stringValue($review->getRawOriginal('status'));

            if (! in_array($status, [ReviewStatus::Approved->value, ReviewStatus::ChangesRequired->value], true)) {
                continue;
            }

            $attemptId = $this->integerValue($review->getAttribute('task_attempt_id'));

            if ($attemptId === null || ! isset($attemptsById[$attemptId])) {
                continue;
            }

            $number = $this->integerValue($attemptsById[$attemptId]->getAttribute('number'));

            if ($number !== null) {
                $reviewsByAttempt[$number][] = $review;
            }
        }

        /** @var array<int, list<AgentRun>> $runsByAttempt */
        $runsByAttempt = [];

        foreach ($task->runs as $run) {
            if ($this->stringValue($run->getRawOriginal('role')) !== AgentRole::Reviewer->value) {
                continue;
            }

            $number = $this->integerValue($run->getAttribute('attempt_number'));

            if ($number !== null) {
                $runsByAttempt[$number][] = $run;
            }
        }

        /** @var array<int, list<AuditEvent>> $eventsByAttempt */
        $eventsByAttempt = [];

        foreach ($task->auditEvents as $event) {
            $eventType = $this->stringValue($event->getRawOriginal('event_type'));

            if (! in_array($eventType, [
                'review.started',
                'review.failed',
                'review.retry_scheduled',
                'review.retry_exhausted',
                'review.completed',
            ], true)) {
                continue;
            }

            $payload = $event->getAttribute('payload');
            $payload = is_array($payload) ? $payload : [];
            $number = $this->integerValue($payload['attempt_number'] ?? null);

            if ($number !== null) {
                $eventsByAttempt[$number][] = $event;
            }
        }

        $attemptNumbers = array_values(array_unique([
            ...array_keys($reviewsByAttempt),
            ...array_keys($runsByAttempt),
            ...array_keys($eventsByAttempt),
        ]));
        sort($attemptNumbers, SORT_NUMERIC);

        $taskAttemptEvidence = array_map(
            fn (TaskAttempt $attempt): array => [
                'id' => (int) $attempt->getKey(),
                'number' => (int) $attempt->getAttribute('number'),
                'status' => $this->stringValue($attempt->getRawOriginal('status')),
            ],
            array_values($attemptsByNumber),
        );

        $cycles = [];

        foreach ($attemptNumbers as $attemptNumber) {
            $reviews = $reviewsByAttempt[$attemptNumber] ?? [];
            usort($reviews, fn (Review $left, Review $right): int => $this->datedModelSortKey($left->getAttribute('completed_at'), (int) $left->getKey()) <=> $this->datedModelSortKey($right->getAttribute('completed_at'), (int) $right->getKey()));

            $runs = $runsByAttempt[$attemptNumber] ?? [];
            usort($runs, fn (AgentRun $left, AgentRun $right): int => $this->datedModelSortKey($left->getAttribute('started_at'), (int) $left->getKey()) <=> $this->datedModelSortKey($right->getAttribute('started_at'), (int) $right->getKey()));

            $events = $eventsByAttempt[$attemptNumber] ?? [];
            usort($events, fn (AuditEvent $left, AuditEvent $right): int => $this->datedModelSortKey($left->getAttribute('occurred_at'), (int) $left->getKey()) <=> $this->datedModelSortKey($right->getAttribute('occurred_at'), (int) $right->getKey()));

            $cycles[] = $this->reviewCycle(
                task: $task,
                attemptNumber: $attemptNumber,
                attempt: $attemptsByNumber[$attemptNumber] ?? null,
                reviews: $reviews,
                runs: $runs,
                events: $events,
                taskAttempts: $taskAttemptEvidence,
            );
        }

        return $cycles;
    }

    /**
     * @param  list<Review>  $reviews
     * @param  list<AgentRun>  $runs
     * @param  list<AuditEvent>  $events
     * @param  list<array{id: int, number: int, status: ?string}>  $taskAttempts
     * @return array<string, mixed>
     */
    private function reviewCycle(
        Task $task,
        int $attemptNumber,
        ?TaskAttempt $attempt,
        array $reviews,
        array $runs,
        array $events,
        array $taskAttempts,
    ): array {
        $startedCount = 0;
        $operationalFailureCount = 0;
        $retryScheduledCount = 0;
        $failureReasons = [];

        foreach ($events as $event) {
            $eventType = $this->stringValue($event->getRawOriginal('event_type'));
            $payload = $event->getAttribute('payload');
            $payload = is_array($payload) ? $payload : [];

            if ($eventType === 'review.started') {
                $startedCount++;
            }

            if ($eventType === 'review.failed') {
                $operationalFailureCount++;
                $reason = $this->stringValue($payload['reason'] ?? null) ?? 'unknown_operational_failure';
                $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
            }

            if ($eventType === 'review.retry_scheduled') {
                $retryScheduledCount++;
            }
        }

        ksort($failureReasons);

        $completedProcessRunCount = 0;
        $failedProcessRunCount = 0;
        $knownTokenUsage = 0;
        $tokenUsageComplete = $runs !== [];
        $knownDurationSeconds = 0;
        $durationComplete = $runs !== [];
        $missingSnapshot = false;

        /** @var array<string, array{harness: string, model: ?string, reasoning_setting: ?string}> $configurations */
        $configurations = [];

        foreach ($runs as $run) {
            $runStatus = $this->stringValue($run->getRawOriginal('status'));

            if ($runStatus === AgentRunStatus::Completed->value) {
                $completedProcessRunCount++;
            }

            if (in_array($runStatus, [AgentRunStatus::Failed->value, AgentRunStatus::Interrupted->value], true)) {
                $failedProcessRunCount++;
            }

            $tokenUsage = $this->tokenUsage->canonicalTotal($run);

            if ($tokenUsage === null) {
                $tokenUsageComplete = false;
            } else {
                $knownTokenUsage += $tokenUsage;
            }

            $durationSeconds = $this->runDurationSeconds($run);

            if ($durationSeconds === null) {
                $durationComplete = false;
            } else {
                $knownDurationSeconds += $durationSeconds;
            }

            $configuration = $this->configurationFromRun($run);

            if ($configuration === null) {
                $missingSnapshot = true;

                continue;
            }

            $configurations[$this->configurationKey($configuration)] = $configuration;
        }

        $configurationStatus = 'attributed';
        $configurationKey = null;
        $configuration = null;

        if ($runs === []) {
            $configurationStatus = 'missing_reviewer_run';
        } elseif ($missingSnapshot) {
            $configurationStatus = 'missing_snapshot';
        } elseif (count($configurations) > 1) {
            $configurationStatus = 'mixed_configuration';
        } else {
            $configurationKey = array_key_first($configurations);

            if ($configurationKey === null) {
                $configurationStatus = 'missing_snapshot';
            } else {
                $configuration = $configurations[$configurationKey];
            }
        }

        $validReview = count($reviews) === 1 ? $reviews[0] : null;
        $reviewStatus = $validReview === null
            ? null
            : $this->stringValue($validReview->getRawOriginal('status'));
        $findingCount = $validReview === null ? 0 : $validReview->findings->count();

        return [
            'task_id' => (int) $task->getKey(),
            'task_key' => (string) $task->getAttribute('key'),
            'project_id' => (int) $task->getAttribute('project_id'),
            'work_type' => $this->stringValue($task->getRawOriginal('work_type')),
            'complexity' => $this->stringValue($task->getRawOriginal('complexity')),
            'attempt_id' => $attempt?->getKey(),
            'attempt_number' => $attemptNumber,
            'review_evidence_status' => count($reviews) > 1
                ? 'ambiguous_multiple_valid_reviews'
                : ($validReview === null ? 'no_valid_review' : 'valid_review'),
            'review_id' => $validReview?->getKey(),
            'review_status' => $reviewStatus,
            'finding_count' => $findingCount,
            'review_started_count' => $startedCount,
            'operational_failure_count' => $operationalFailureCount,
            'review_retry_count' => $operationalFailureCount,
            'retry_scheduled_count' => $retryScheduledCount,
            'operational_failure_reasons' => $failureReasons,
            'reviewer_run_count' => count($runs),
            'completed_process_run_count' => $completedProcessRunCount,
            'failed_process_run_count' => $failedProcessRunCount,
            'structured_output_valid_count' => $validReview !== null && $completedProcessRunCount > 0 ? 1 : 0,
            'first_attempt_review_completion' => $startedCount === 1
                && $operationalFailureCount === 0
                && $validReview !== null,
            'configuration_status' => $configurationStatus,
            'configuration_key' => $configurationKey,
            'configuration' => $configuration,
            'observed_configurations' => array_values($configurations),
            'known_token_usage' => $knownTokenUsage,
            'token_usage_complete' => $tokenUsageComplete,
            'total_token_usage' => $tokenUsageComplete ? $knownTokenUsage : null,
            'known_execution_duration_seconds' => $knownDurationSeconds,
            'duration_complete' => $durationComplete,
            'total_execution_duration_seconds' => $durationComplete
                ? $knownDurationSeconds
                : null,
            'review_completed_at' => $this->dateTimestamp($validReview?->getAttribute('completed_at')),
            '_task_attempts' => $taskAttempts,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     * @param  list<string>  $broadenedDimensions
     * @return array<string, mixed>
     */
    private function evaluateCandidate(
        Project $project,
        array $cycles,
        int $fallbackLevel,
        string $fallbackKey,
        ?TaskWorkType $workType,
        ?TaskComplexity $complexity,
        array $broadenedDimensions,
    ): array {
        $filtered = array_values(array_filter(
            $cycles,
            function (array $cycle) use ($workType, $complexity): bool {
                if ($workType !== null && ($cycle['work_type'] ?? null) !== $workType->value) {
                    return false;
                }

                if ($complexity !== null && ($cycle['complexity'] ?? null) !== $complexity->value) {
                    return false;
                }

                return true;
            },
        ));

        $diagnostics = $this->summarizeCycles($filtered);
        $sample = is_array($diagnostics['sample'] ?? null) ? $diagnostics['sample'] : [];
        $configurationCount = (int) ($sample['configuration_count'] ?? 0);
        $validAttributedReviewCount = (int) ($sample['attributed_valid_review_count'] ?? 0);

        return [
            'fallback_level' => $fallbackLevel,
            'fallback_key' => $fallbackKey,
            'cohort' => $this->cohortMetadata(
                project: $project,
                fallbackLevel: $fallbackLevel,
                fallbackKey: $fallbackKey,
                workType: $workType,
                complexity: $complexity,
                broadenedDimensions: $broadenedDimensions,
            ),
            'sample' => $sample,
            'directly_comparable' => $configurationCount >= 2 && $validAttributedReviewCount >= 2,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function selectCandidate(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if (($candidate['directly_comparable'] ?? false) === true) {
                return $candidate;
            }
        }

        $selected = $candidates[0];

        foreach ($candidates as $candidate) {
            $candidateSample = is_array($candidate['sample'] ?? null) ? $candidate['sample'] : [];
            $selectedSample = is_array($selected['sample'] ?? null) ? $selected['sample'] : [];
            $candidateReviews = (int) ($candidateSample['attributed_valid_review_count'] ?? 0);
            $selectedReviews = (int) ($selectedSample['attributed_valid_review_count'] ?? 0);

            if ($candidateReviews > $selectedReviews) {
                $selected = $candidate;

                continue;
            }

            if ($candidateReviews < $selectedReviews) {
                continue;
            }

            $candidateConfigurations = (int) ($candidateSample['configuration_count'] ?? 0);
            $selectedConfigurations = (int) ($selectedSample['configuration_count'] ?? 0);

            if ($candidateConfigurations > $selectedConfigurations) {
                $selected = $candidate;
            }
        }

        return $selected;
    }

    /**
     * @param  list<string>  $broadenedDimensions
     * @return array<string, mixed>
     */
    private function cohortMetadata(
        Project $project,
        int $fallbackLevel,
        string $fallbackKey,
        ?TaskWorkType $workType,
        ?TaskComplexity $complexity,
        array $broadenedDimensions,
    ): array {
        $projectId = (int) $project->getKey();
        $projectName = (string) $project->getAttribute('name');

        return [
            'machine_label' => sprintf(
                'reviewer:project:%d:work_type:%s:complexity:%s:fallback:%d',
                $projectId,
                $workType === null ? '*' : $workType->value,
                $complexity === null ? '*' : $complexity->value,
                $fallbackLevel,
            ),
            'human_label' => sprintf(
                'Reviewer | project/repository %s (#%d) | work_type=%s | complexity=%s',
                $projectName,
                $projectId,
                $workType === null ? 'all (broadened; includes unknown)' : $workType->value,
                $complexity === null ? 'all (broadened; includes unknown)' : $complexity->value,
            ),
            'fallback_level' => $fallbackLevel,
            'fallback_key' => $fallbackKey,
            'filters' => [
                'workflow_role' => AgentRole::Reviewer->value,
                'project_repository' => [
                    'project_id' => $projectId,
                    'project_name' => $projectName,
                ],
                'work_type' => $workType?->value,
                'complexity' => $complexity?->value,
            ],
            'broadened_dimensions' => $broadenedDimensions,
            'includes_unknown_metadata' => [
                'work_type' => $workType === null,
                'complexity' => $complexity === null,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     * @return array<string, mixed>
     */
    private function summarizeCycles(array $cycles): array
    {
        usort($cycles, fn (array $left, array $right): int => [
            (int) ($left['task_id'] ?? 0),
            (int) ($left['attempt_number'] ?? 0),
        ] <=> [
            (int) ($right['task_id'] ?? 0),
            (int) ($right['attempt_number'] ?? 0),
        ]);

        $startedCount = array_sum(array_map(
            fn (array $cycle): int => (int) ($cycle['review_started_count'] ?? 0),
            $cycles,
        ));
        $operationalFailureCount = array_sum(array_map(
            fn (array $cycle): int => (int) ($cycle['operational_failure_count'] ?? 0),
            $cycles,
        ));
        $completedProcessRunCount = array_sum(array_map(
            fn (array $cycle): int => (int) ($cycle['completed_process_run_count'] ?? 0),
            $cycles,
        ));

        $validCycles = array_values(array_filter(
            $cycles,
            fn (array $cycle): bool => in_array(
                $cycle['review_status'] ?? null,
                [ReviewStatus::Approved->value, ReviewStatus::ChangesRequired->value],
                true,
            ),
        ));
        $attributedCycles = array_values(array_filter(
            $cycles,
            fn (array $cycle): bool => ($cycle['configuration_status'] ?? null) === 'attributed'
                && is_string($cycle['configuration_key'] ?? null)
                && $cycle['configuration_key'] !== ''
                && is_array($cycle['configuration'] ?? null),
        ));
        $attributedValidCycles = array_values(array_filter(
            $attributedCycles,
            fn (array $cycle): bool => in_array(
                $cycle['review_status'] ?? null,
                [ReviewStatus::Approved->value, ReviewStatus::ChangesRequired->value],
                true,
            ),
        ));

        $failureReasons = [];

        foreach ($cycles as $cycle) {
            $reasons = is_array($cycle['operational_failure_reasons'] ?? null)
                ? $cycle['operational_failure_reasons']
                : [];

            foreach ($reasons as $reason => $count) {
                if (! is_string($reason) || ! is_int($count)) {
                    continue;
                }

                $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + $count;
            }
        }

        ksort($failureReasons);

        /** @var array<string, list<array<string, mixed>>> $configurationGroups */
        $configurationGroups = [];

        foreach ($attributedCycles as $cycle) {
            $configurationGroups[(string) $cycle['configuration_key']][] = $cycle;
        }

        ksort($configurationGroups);

        $configurationDiagnostics = [];

        foreach ($configurationGroups as $configurationKey => $group) {
            $configurationDiagnostics[] = $this->configurationDiagnostics(
                configurationKey: $configurationKey,
                cycles: $group,
                selectedCohortCycles: $cycles,
            );
        }

        $changesRequiredCount = $this->decisionCount($validCycles, ReviewStatus::ChangesRequired);
        $operationalSuccessCount = count(array_filter(
            $validCycles,
            fn (array $cycle): bool => (int) ($cycle['review_started_count'] ?? 0) > 0,
        ));
        $structuredValidCount = array_sum(array_map(
            fn (array $cycle): int => (int) ($cycle['structured_output_valid_count'] ?? 0),
            $cycles,
        ));
        $firstAttemptEligibleCycles = array_values(array_filter(
            $cycles,
            fn (array $cycle): bool => (int) ($cycle['review_started_count'] ?? 0) > 0,
        ));
        $firstAttemptCompletedCount = count(array_filter(
            $firstAttemptEligibleCycles,
            fn (array $cycle): bool => ($cycle['first_attempt_review_completion'] ?? false) === true,
        ));

        $unattributedCycles = array_map(
            fn (array $cycle): array => [
                'task_id' => (int) ($cycle['task_id'] ?? 0),
                'task_key' => $cycle['task_key'] ?? null,
                'attempt_number' => (int) ($cycle['attempt_number'] ?? 0),
                'review_status' => $cycle['review_status'] ?? null,
                'configuration_status' => $cycle['configuration_status'] ?? null,
                'reviewer_run_count' => (int) ($cycle['reviewer_run_count'] ?? 0),
            ],
            array_values(array_filter(
                $cycles,
                fn (array $cycle): bool => ($cycle['configuration_status'] ?? null) !== 'attributed',
            )),
        );

        return [
            'sample' => [
                'review_cycle_count' => count($cycles),
                'review_started_invocation_count' => $startedCount,
                'valid_review_count' => count($validCycles),
                'attributed_review_cycle_count' => count($attributedCycles),
                'attributed_valid_review_count' => count($attributedValidCycles),
                'configuration_count' => count($configurationDiagnostics),
                'unattributed_cycle_count' => count($unattributedCycles),
                'completed_process_run_count' => $completedProcessRunCount,
                'operational_failure_count' => $operationalFailureCount,
            ],
            'rates' => [
                'operational_success' => $this->rate($operationalSuccessCount, $startedCount),
                'structured_output_validity' => $this->rate($structuredValidCount, $completedProcessRunCount),
                'review_retry' => $this->rate($operationalFailureCount, $startedCount),
                'changes_required' => [
                    'value' => $this->rate($changesRequiredCount, count($validCycles)),
                    'diagnostic_only' => true,
                    'interpretation' => 'Neither a high nor a low raw changes_required rate is treated as Reviewer quality by itself.',
                ],
                'first_attempt_review_completion' => $this->rate(
                    $firstAttemptCompletedCount,
                    count($firstAttemptEligibleCycles),
                ),
            ],
            'operational_failure_reasons' => $failureReasons,
            'configuration_diagnostics' => $configurationDiagnostics,
            'approval_consistency' => $this->approvalConsistency($configurationDiagnostics),
            'codex_claude_divergence' => $this->codexClaudeDivergence($attributedValidCycles),
            'actionable_finding_follow_through' => $this->findingFollowThrough($cycles),
            'unattributed_cycles' => $unattributedCycles,
            'review_cycles' => array_map(
                fn (array $cycle): array => $this->publicCycle($cycle),
                $cycles,
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     * @param  list<array<string, mixed>>  $selectedCohortCycles
     * @return array<string, mixed>
     */
    private function configurationDiagnostics(
        string $configurationKey,
        array $cycles,
        array $selectedCohortCycles,
    ): array {
        $configuration = is_array($cycles[0]['configuration'] ?? null)
            ? $cycles[0]['configuration']
            : [];
        $startedCount = array_sum(array_map(
            fn (array $cycle): int => (int) ($cycle['review_started_count'] ?? 0),
            $cycles,
        ));
        $failureCount = array_sum(array_map(
            fn (array $cycle): int => (int) ($cycle['operational_failure_count'] ?? 0),
            $cycles,
        ));
        $completedProcessRunCount = array_sum(array_map(
            fn (array $cycle): int => (int) ($cycle['completed_process_run_count'] ?? 0),
            $cycles,
        ));
        $validCycles = array_values(array_filter(
            $cycles,
            fn (array $cycle): bool => in_array(
                $cycle['review_status'] ?? null,
                [ReviewStatus::Approved->value, ReviewStatus::ChangesRequired->value],
                true,
            ),
        ));
        $approvedCount = $this->decisionCount($validCycles, ReviewStatus::Approved);
        $operationalSuccessCount = count(array_filter(
            $validCycles,
            fn (array $cycle): bool => (int) ($cycle['review_started_count'] ?? 0) > 0,
        ));
        $structuredValidCount = array_sum(array_map(
            fn (array $cycle): int => (int) ($cycle['structured_output_valid_count'] ?? 0),
            $cycles,
        ));
        $changesRequiredCount = $this->decisionCount($validCycles, ReviewStatus::ChangesRequired);
        $firstAttemptEligibleCycles = array_values(array_filter(
            $cycles,
            fn (array $cycle): bool => (int) ($cycle['review_started_count'] ?? 0) > 0,
        ));
        $firstAttemptCompletedCount = count(array_filter(
            $firstAttemptEligibleCycles,
            fn (array $cycle): bool => ($cycle['first_attempt_review_completion'] ?? false) === true,
        ));

        $tokenValues = $this->completeMetricValues($cycles, 'total_token_usage');
        $durationValues = $this->completeMetricValues($cycles, 'total_execution_duration_seconds');

        return [
            'configuration_key' => $configurationKey,
            'configuration' => $configuration,
            'sample' => [
                'review_cycle_count' => count($cycles),
                'review_started_invocation_count' => $startedCount,
                'valid_review_count' => count($validCycles),
                'completed_process_run_count' => $completedProcessRunCount,
            ],
            'rates' => [
                'operational_success' => $this->rate($operationalSuccessCount, $startedCount),
                'structured_output_validity' => $this->rate($structuredValidCount, $completedProcessRunCount),
                'review_retry' => $this->rate($failureCount, $startedCount),
                'changes_required' => [
                    'value' => $this->rate($changesRequiredCount, count($validCycles)),
                    'diagnostic_only' => true,
                ],
                'first_attempt_review_completion' => $this->rate(
                    $firstAttemptCompletedCount,
                    count($firstAttemptEligibleCycles),
                ),
            ],
            'outcomes' => [
                'approved_count' => $approvedCount,
                'changes_required_count' => $changesRequiredCount,
                'approval_rate' => $this->rate($approvedCount, count($validCycles)),
                'changes_required_rate' => $this->rate($changesRequiredCount, count($validCycles)),
            ],
            'review_retry_count' => $failureCount,
            'medians' => [
                'token_consumption' => $this->median($tokenValues),
                'duration_seconds' => $this->median($durationValues),
            ],
            'telemetry' => [
                'token_complete_cycle_count' => count($tokenValues),
                'token_incomplete_cycle_count' => count($cycles) - count($tokenValues),
                'duration_complete_cycle_count' => count($durationValues),
                'duration_incomplete_cycle_count' => count($cycles) - count($durationValues),
                'retry_cost_policy' => 'Per-cycle token and duration totals include every persisted Reviewer AgentRun in the TaskAttempt review cycle, including operational retry runs. Incomplete telemetry is null, never zero-filled.',
            ],
            'actionable_finding_follow_through' => $this->findingFollowThrough(
                cycles: $selectedCohortCycles,
                sourceConfigurationKey: $configurationKey,
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $configurationDiagnostics
     * @return array<string, mixed>
     */
    private function approvalConsistency(array $configurationDiagnostics): array
    {
        $comparable = array_values(array_filter(
            $configurationDiagnostics,
            fn (array $diagnostic): bool => (int) ($diagnostic['sample']['valid_review_count'] ?? 0) > 0,
        ));

        if (count($comparable) < 2) {
            return [
                'available' => false,
                'configuration_count' => count($comparable),
                'reason' => 'At least two attributed Reviewer configurations with valid decisions are required to display observational consistency/divergence.',
                'ground_truth' => 'not_available',
            ];
        }

        $approvalRates = [];
        $changesRequiredRates = [];
        $configurations = [];

        foreach ($comparable as $diagnostic) {
            $approvalRate = $diagnostic['outcomes']['approval_rate'] ?? null;
            $changesRequiredRate = $diagnostic['outcomes']['changes_required_rate'] ?? null;

            if (is_float($approvalRate) || is_int($approvalRate)) {
                $approvalRates[] = (float) $approvalRate;
            }

            if (is_float($changesRequiredRate) || is_int($changesRequiredRate)) {
                $changesRequiredRates[] = (float) $changesRequiredRate;
            }

            $configurations[] = [
                'configuration_key' => $diagnostic['configuration_key'] ?? null,
                'configuration' => $diagnostic['configuration'] ?? null,
                'sample_count' => (int) ($diagnostic['sample']['valid_review_count'] ?? 0),
                'approved_count' => (int) ($diagnostic['outcomes']['approved_count'] ?? 0),
                'changes_required_count' => (int) ($diagnostic['outcomes']['changes_required_count'] ?? 0),
                'approval_rate' => $approvalRate,
                'changes_required_rate' => $changesRequiredRate,
            ];
        }

        return [
            'available' => true,
            'configuration_count' => count($comparable),
            'approval_rate_range' => $this->range($approvalRates),
            'changes_required_rate_range' => $this->range($changesRequiredRates),
            'configurations' => $configurations,
            'ground_truth' => 'not_available',
            'interpretation' => 'Observational distribution consistency only. A smaller or larger outcome-rate gap does not establish correctness, strictness, or Reviewer quality without independent adjudication.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     * @return array<string, mixed>
     */
    private function codexClaudeDivergence(array $cycles): array
    {
        $byHarness = [
            AgentHarness::Codex->value => [],
            AgentHarness::ClaudeCode->value => [],
        ];

        foreach ($cycles as $cycle) {
            $configuration = is_array($cycle['configuration'] ?? null)
                ? $cycle['configuration']
                : [];
            $harness = $configuration['harness'] ?? null;

            if (is_string($harness) && array_key_exists($harness, $byHarness)) {
                $byHarness[$harness][] = $cycle;
            }
        }

        $codex = $this->harnessOutcomeSummary($byHarness[AgentHarness::Codex->value]);
        $claude = $this->harnessOutcomeSummary($byHarness[AgentHarness::ClaudeCode->value]);

        if ($codex['sample_count'] === 0 || $claude['sample_count'] === 0) {
            return [
                'available' => false,
                'codex' => $codex,
                'claude_code' => $claude,
                'reason' => 'Comparable selected-cohort Reviewer decisions do not contain both Codex and Claude Code evidence.',
                'ground_truth' => 'not_available',
            ];
        }

        return [
            'available' => true,
            'codex' => $codex,
            'claude_code' => $claude,
            'absolute_rate_delta' => [
                'approval' => round(abs((float) $codex['approval_rate'] - (float) $claude['approval_rate']), 6),
                'changes_required' => round(abs((float) $codex['changes_required_rate'] - (float) $claude['changes_required_rate']), 6),
            ],
            'ground_truth' => 'not_available',
            'interpretation' => 'Observational Codex-vs-Claude outcome divergence within the selected comparable task cohort. Exact model/reasoning configuration mixtures are retained below and no harness is declared correct from disagreement alone.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     * @return array<string, mixed>
     */
    private function harnessOutcomeSummary(array $cycles): array
    {
        $approvedCount = $this->decisionCount($cycles, ReviewStatus::Approved);
        $changesRequiredCount = $this->decisionCount($cycles, ReviewStatus::ChangesRequired);
        $configurationKeys = [];

        foreach ($cycles as $cycle) {
            $key = $cycle['configuration_key'] ?? null;

            if (is_string($key) && $key !== '') {
                $configurationKeys[$key] = true;
            }
        }

        ksort($configurationKeys);
        $sampleCount = count($cycles);

        return [
            'sample_count' => $sampleCount,
            'approved_count' => $approvedCount,
            'changes_required_count' => $changesRequiredCount,
            'approval_rate' => $this->rate($approvedCount, $sampleCount),
            'changes_required_rate' => $this->rate($changesRequiredCount, $sampleCount),
            'configuration_keys' => array_keys($configurationKeys),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     * @return array<string, mixed>
     */
    private function findingFollowThrough(
        array $cycles,
        ?string $sourceConfigurationKey = null,
    ): array {
        $sources = array_values(array_filter(
            $cycles,
            function (array $cycle) use ($sourceConfigurationKey): bool {
                if (($cycle['review_status'] ?? null) !== ReviewStatus::ChangesRequired->value) {
                    return false;
                }

                if ($sourceConfigurationKey !== null && ($cycle['configuration_key'] ?? null) !== $sourceConfigurationKey) {
                    return false;
                }

                return true;
            },
        ));

        $evidence = [];
        $withActionableFindings = 0;
        $withCorrectedCoderAttempt = 0;
        $eventualApprovalCount = 0;

        foreach ($sources as $source) {
            $findingCount = (int) ($source['finding_count'] ?? 0);
            $sourceAttemptNumber = (int) ($source['attempt_number'] ?? 0);
            $taskId = (int) ($source['task_id'] ?? 0);
            $taskAttempts = is_array($source['_task_attempts'] ?? null)
                ? $source['_task_attempts']
                : [];
            $correctedAttempt = null;

            foreach ($taskAttempts as $taskAttempt) {
                if (! is_array($taskAttempt)) {
                    continue;
                }

                $number = $this->integerValue($taskAttempt['number'] ?? null);

                if ($number !== null && $number > $sourceAttemptNumber) {
                    $correctedAttempt = $taskAttempt;

                    break;
                }
            }

            $approvalCycle = null;

            if ($correctedAttempt !== null) {
                foreach ($cycles as $candidate) {
                    if ((int) ($candidate['task_id'] ?? 0) !== $taskId) {
                        continue;
                    }

                    if (($candidate['review_status'] ?? null) !== ReviewStatus::Approved->value) {
                        continue;
                    }

                    if ((int) ($candidate['attempt_number'] ?? 0) < (int) $correctedAttempt['number']) {
                        continue;
                    }

                    $approvalCycle = $candidate;

                    break;
                }
            }

            if ($findingCount > 0) {
                $withActionableFindings++;
            }

            if ($correctedAttempt !== null) {
                $withCorrectedCoderAttempt++;
            }

            if ($approvalCycle !== null) {
                $eventualApprovalCount++;
            }

            $status = match (true) {
                $findingCount < 1 => 'missing_actionable_finding_evidence',
                $correctedAttempt === null => 'awaiting_corrected_coder_attempt',
                $approvalCycle === null => 'corrected_attempt_without_eventual_approval',
                default => 'eventual_approval_observed',
            };

            $evidence[] = [
                'task_id' => $taskId,
                'task_key' => $source['task_key'] ?? null,
                'changes_required_review_id' => $source['review_id'] ?? null,
                'changes_required_attempt_number' => $sourceAttemptNumber,
                'finding_count' => $findingCount,
                'source_configuration_key' => $source['configuration_key'] ?? null,
                'corrected_coder_attempt' => $correctedAttempt,
                'eventual_approval_review_id' => $approvalCycle['review_id'] ?? null,
                'eventual_approval_attempt_number' => $approvalCycle['attempt_number'] ?? null,
                'status' => $status,
                'evidence_scope' => 'chain_level_only',
            ];
        }

        return [
            'changes_required_review_count' => count($sources),
            'with_actionable_findings_count' => $withActionableFindings,
            'with_corrected_coder_attempt_count' => $withCorrectedCoderAttempt,
            'eventual_approval_count' => $eventualApprovalCount,
            'eventual_approval_rate' => $this->rate($eventualApprovalCount, $withActionableFindings),
            'interpretation' => 'Follow-through proves only the durable chain changes_required → later Coder TaskAttempt → eventual approval. It does not claim each individual finding was independently adjudicated as correct.',
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     */
    private function decisionCount(array $cycles, ReviewStatus $status): int
    {
        return count(array_filter(
            $cycles,
            fn (array $cycle): bool => ($cycle['review_status'] ?? null) === $status->value,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     * @return list<float>
     */
    private function completeMetricValues(array $cycles, string $field): array
    {
        $values = [];

        foreach ($cycles as $cycle) {
            $value = $cycle[$field] ?? null;

            if (is_int($value) || is_float($value)) {
                $values[] = (float) $value;
            }
        }

        return $values;
    }

    /**
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
     * @param  list<float>  $values
     * @return array{min: float, max: float, delta: float}|null
     */
    private function range(array $values): ?array
    {
        if ($values === []) {
            return null;
        }

        $min = min($values);
        $max = max($values);

        return [
            'min' => round($min, 6),
            'max' => round($max, 6),
            'delta' => round($max - $min, 6),
        ];
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        if ($denominator < 1) {
            return null;
        }

        return round($numerator / $denominator, 6);
    }

    private function runDurationSeconds(AgentRun $run): ?int
    {
        $startedAt = $run->getAttribute('started_at');
        $finishedAt = $run->getAttribute('finished_at');

        if (! $startedAt instanceof DateTimeInterface || ! $finishedAt instanceof DateTimeInterface) {
            return null;
        }

        return max(0, $finishedAt->getTimestamp() - $startedAt->getTimestamp());
    }

    /**
     * @return array{harness: string, model: ?string, reasoning_setting: ?string}|null
     */
    private function configurationFromRun(AgentRun $run): ?array
    {
        $snapshot = $run->getAttribute('configuration_snapshot');

        if (! is_array($snapshot)) {
            return null;
        }

        $agent = $snapshot['agent'] ?? null;

        if (! is_array($agent)) {
            return null;
        }

        $role = $agent['role'] ?? null;
        $harness = $agent['harness'] ?? null;
        $model = $agent['model'] ?? null;
        $reasoningSetting = $agent['reasoning_setting'] ?? null;

        if ($role !== AgentRole::Reviewer->value || ! is_string($harness) || $harness === '') {
            return null;
        }

        if ($model !== null && ! is_string($model)) {
            return null;
        }

        if ($reasoningSetting !== null && ! is_string($reasoningSetting)) {
            return null;
        }

        $persistedHarness = $this->stringValue($run->getRawOriginal('harness'));

        if ($persistedHarness === null || $persistedHarness !== $harness) {
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

    /**
     * @param  array<string, mixed>  $cycle
     * @return array<string, mixed>
     */
    private function publicCycle(array $cycle): array
    {
        unset($cycle['_task_attempts']);

        return $cycle;
    }

    /**
     * @return array<string, mixed>
     */
    private function methodology(): array
    {
        return [
            'version' => self::MethodologyVersion,
            'role' => AgentRole::Reviewer->value,
            'unit_of_analysis' => 'One Task + TaskAttempt Reviewer cycle. Operational retries remain in the same cycle; a changes_required correction is represented by a later Coder TaskAttempt.',
            'operational_success' => [
                'numerator' => 'Persisted valid Review decisions (approved or changes_required) from cycles with review.started evidence.',
                'denominator' => 'review.started audit events in the selected cohort.',
                'policy' => 'A Reviewer invocation succeeds operationally only when it produces a processable, schema-valid decision. Decision polarity is irrelevant to operational success.',
            ],
            'structured_output_validity' => [
                'numerator' => 'Persisted valid Review decisions from cycles containing at least one completed Reviewer AgentRun.',
                'denominator' => 'Reviewer AgentRuns that completed at the process/harness layer.',
                'policy' => 'Completed runs followed by missing or invalid structured decisions lower validity; execution failures and agent-misconfiguration events are operational failures but not structured-output failures.',
            ],
            'review_retry' => [
                'count' => 'Persisted review.failed audit events.',
                'rate_denominator' => 'review.started audit events.',
                'policy' => 'Operational retries/failures remain visible even when a later Reviewer invocation succeeds.',
            ],
            'changes_required' => [
                'denominator' => 'Persisted valid Review decisions only.',
                'interpretation' => 'Diagnostic only. Raw approval or raw changes_required rate is never a standalone quality signal.',
            ],
            'first_attempt_review_completion' => [
                'numerator' => 'Review cycles with exactly one review.started event, no review.failed event, and one persisted valid Review.',
                'denominator' => 'Review cycles with review.started evidence.',
            ],
            'actionable_finding_follow_through' => [
                'source' => 'Persisted changes_required Review with ReviewFinding evidence.',
                'correction' => 'First later TaskAttempt for the same Task.',
                'eventual_approval' => 'A later persisted approved Review after that correction attempt.',
                'ground_truth_limit' => 'Chain-level follow-through does not prove each finding was independently correct or resolved.',
            ],
            'configuration_attribution' => 'A cycle is attributable only when every persisted Reviewer AgentRun in that cycle has a valid immutable configuration_snapshot.agent role/harness/model/reasoning_setting and all runs resolve to one identical configuration. Mutable Agent bindings/configuration are never consulted.',
            'mixed_configuration_policy' => 'Cycles containing multiple Reviewer configurations remain visible but are excluded from configuration-level consistency/divergence so retry cost/outcomes are not assigned arbitrarily.',
            'legacy_policy' => 'Reviews/runs without sufficient immutable configuration evidence remain explicit unattributed diagnostics and are never backfilled from current Agent configuration.',
            'telemetry' => 'Per-cycle token and duration totals include all Reviewer AgentRuns in that cycle, including operational retries. Median is used across complete cycle totals; missing telemetry remains null and is not zero-filled.',
            'consistency' => 'Approval/change-request distributions are compared only inside the selected project/work-type/complexity cohort. Distribution gaps are observational and are not ground truth.',
            'codex_claude_divergence' => 'Codex-vs-Claude outcome-rate deltas are shown only when both harnesses have attributed valid decisions in the selected comparable task cohort. Exact configuration keys remain visible; no winner/correct harness is inferred.',
            'workflow_mutation' => 'None. This service reads durable evidence and returns diagnostics only; it does not write Reviews, Tasks, Agent bindings/configuration, workflow state, or recommendations.',
        ];
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

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function dateTimestamp(mixed $value): ?int
    {
        return $value instanceof DateTimeInterface ? $value->getTimestamp() : null;
    }

    private function datedModelSortKey(mixed $date, int $id): int
    {
        $timestamp = $this->dateTimestamp($date) ?? PHP_INT_MAX;

        if ($timestamp === PHP_INT_MAX) {
            return PHP_INT_MAX - min(PHP_INT_MAX - 1, $id);
        }

        return ($timestamp * 1000000) + min(999999, $id);
    }
}
