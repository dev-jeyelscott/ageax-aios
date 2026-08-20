<?php

namespace App\Services;

use App\AgentRole;
use App\Models\Project;
use App\Models\Task;
use App\TaskComplexity;
use App\TaskWorkType;

final class CoderHarnessComparableCohortScorecards
{
    public const int SchemaVersion = 1;

    public const string FallbackPolicyVersion = 'coder_comparable_cohort_v1';

    public const string ConfidenceInsufficientData = 'insufficient_data';

    public const string ConfidencePreliminary = 'preliminary';

    public const string ConfidenceRecommendationEligible = 'recommendation_eligible';

    private const int PreliminaryMinimumCompletedTasks = 5;

    private const int RecommendationEligibleMinimumCompletedTasks = 20;

    public function __construct(
        private readonly CoderHarnessOutcomeMetrics $metrics,
    ) {}

    /**
     * Build a comparable Coder cohort for one project/repository boundary.
     *
     * The deterministic fallback order preserves workflow role and project/repository,
     * then broadens work_type before complexity. Harness/model/reasoning remain exact
     * configuration identities inside every selected cohort and are never merged.
     *
     * Historical null work_type/complexity values are excluded from exact filters and
     * enter only after their dimension is explicitly broadened.
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
        $baseMetrics = $this->metrics->calculate($projectTasks);

        $projectOutcomes = $this->projectOutcomes(
            $project,
            $this->recordList($baseMetrics['task_outcomes'] ?? null),
        );

        $candidates = [
            $this->evaluateCandidate(
                project: $project,
                outcomes: $projectOutcomes,
                fallbackLevel: 0,
                fallbackKey: 'exact_project_work_type_complexity',
                workType: $workType,
                complexity: $complexity,
                broadenedDimensions: [],
            ),
            $this->evaluateCandidate(
                project: $project,
                outcomes: $projectOutcomes,
                fallbackLevel: 1,
                fallbackKey: 'same_project_same_complexity_all_work_types',
                workType: null,
                complexity: $complexity,
                broadenedDimensions: ['work_type'],
            ),
            $this->evaluateCandidate(
                project: $project,
                outcomes: $projectOutcomes,
                fallbackLevel: 2,
                fallbackKey: 'same_project_all_work_types_and_complexities',
                workType: null,
                complexity: null,
                broadenedDimensions: ['work_type', 'complexity'],
            ),
        ];

        $selected = $this->selectCandidate($candidates);
        $score = $selected['score'];

        $configurationScores = $this->rankConfigurationScores(
            $this->recordList($score['configuration_scores'] ?? null),
        );

        $completedTaskCount = (int) $selected['comparable_completed_task_count'];
        $confidence = $this->confidenceFor($completedTaskCount);
        $scoreVersion = (int) ($score['schema_version'] ?? CoderHarnessOutcomeMetrics::SchemaVersion);

        return [
            'schema_version' => self::SchemaVersion,
            'score_version' => $scoreVersion,
            'fallback_policy' => [
                'version' => self::FallbackPolicyVersion,
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
                'unknown_metadata_policy' => 'Historical null metadata is excluded from exact filters and participates only after its dimension is explicitly broadened.',
            ],
            'recommendation_policy' => [
                'confidence_basis' => 'Comparable successfully completed attributable Tasks in the selected cohort.',
                'tie_policy' => 'Equal composite scores at the persisted score precision produce no leader. P3-020 does not claim statistical significance beyond the deterministic score methodology.',
                'routing_policy' => 'advisory_only',
            ],
            'requested_cohort' => [
                'role' => AgentRole::Coder->value,
                'project_repository' => [
                    'project_id' => (int) $project->getKey(),
                    'project_name' => (string) $project->getAttribute('name'),
                ],
                'work_type' => $workType->value,
                'complexity' => $complexity->value,
            ],
            'selected_cohort' => $selected['cohort'],
            'fallback_evaluations' => array_map(
                fn (array $candidate): array => $this->fallbackEvaluation($candidate),
                $candidates,
            ),
            'sample' => $this->sampleEvidence(
                $score,
                $completedTaskCount,
                count($configurationScores),
            ),
            'confidence' => [
                'level' => $confidence,
                'comparable_completed_task_count' => $completedTaskCount,
                'preliminary_minimum' => self::PreliminaryMinimumCompletedTasks,
                'recommendation_eligible_minimum' => self::RecommendationEligibleMinimumCompletedTasks,
            ],
            'methodology' => is_array($score['methodology'] ?? null)
                ? $score['methodology']
                : [],
            'reference' => is_array($score['reference'] ?? null)
                ? $score['reference']
                : [],
            'configuration_scores' => $configurationScores,
            'unattributed_outcomes' => is_array($score['unattributed_outcomes'] ?? null)
                ? $score['unattributed_outcomes']
                : [],
            'recommendation' => $this->recommendation(
                configurationScores: $configurationScores,
                confidence: $confidence,
                completedTaskCount: $completedTaskCount,
                scoreVersion: $scoreVersion,
                cohort: is_array($selected['cohort'] ?? null)
                    ? $selected['cohort']
                    : [],
            ),
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
     * @param  list<array<string, mixed>>  $outcomes
     * @return list<array<string, mixed>>
     */
    private function projectOutcomes(Project $project, array $outcomes): array
    {
        $projectId = (int) $project->getKey();

        /** @var array<int, array<string, mixed>> $unique */
        $unique = [];

        foreach ($outcomes as $outcome) {
            $taskId = $outcome['task_id'] ?? null;
            $outcomeProjectId = $outcome['project_id'] ?? null;

            if (! is_int($taskId) || $taskId < 1) {
                continue;
            }

            if (! is_int($outcomeProjectId) || $outcomeProjectId !== $projectId) {
                continue;
            }

            if (! isset($unique[$taskId])) {
                $unique[$taskId] = $outcome;
            }
        }

        ksort($unique, SORT_NUMERIC);

        return array_values($unique);
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     * @param  list<string>  $broadenedDimensions
     * @return array<string, mixed>
     */
    private function evaluateCandidate(
        Project $project,
        array $outcomes,
        int $fallbackLevel,
        string $fallbackKey,
        ?TaskWorkType $workType,
        ?TaskComplexity $complexity,
        array $broadenedDimensions,
    ): array {
        $filteredOutcomes = array_values(array_filter(
            $outcomes,
            function (array $outcome) use ($workType, $complexity): bool {
                if (
                    $workType !== null
                    && ($outcome['work_type'] ?? null) !== $workType->value
                ) {
                    return false;
                }

                if (
                    $complexity !== null
                    && ($outcome['complexity'] ?? null) !== $complexity->value
                ) {
                    return false;
                }

                return true;
            },
        ));

        $score = $this->metrics->scoreOutcomes($filteredOutcomes);

        $completedTaskCount = (int) (
            $score['reference']['successful_attributed_task_count'] ?? 0
        );

        $configurationCount = is_array($score['configuration_scores'] ?? null)
            ? count($score['configuration_scores'])
            : 0;

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
            'score' => $score,
            'comparable_completed_task_count' => $completedTaskCount,
            'configuration_count' => $configurationCount,
            'confidence' => $this->confidenceFor($completedTaskCount),
        ];
    }

    /**
     * Prefer the most-specific cohort that reaches recommendation eligibility.
     * If none does, prefer the most-specific preliminary cohort. If every cohort
     * remains insufficient, use the cohort with the most comparable completed
     * evidence and break ties toward greater specificity.
     *
     * Candidates with fewer than two scorable configurations cannot produce a
     * comparison, so an otherwise equivalent candidate with competing
     * configurations is preferred.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function selectCandidate(array $candidates): array
    {
        foreach ([
            self::ConfidenceRecommendationEligible,
            self::ConfidencePreliminary,
        ] as $targetConfidence) {
            foreach ($candidates as $candidate) {
                if (
                    $candidate['confidence'] === $targetConfidence
                    && (int) $candidate['configuration_count'] >= 2
                ) {
                    return $candidate;
                }
            }
        }

        foreach ([
            self::ConfidenceRecommendationEligible,
            self::ConfidencePreliminary,
        ] as $targetConfidence) {
            foreach ($candidates as $candidate) {
                if ($candidate['confidence'] === $targetConfidence) {
                    return $candidate;
                }
            }
        }

        $selected = $candidates[0];

        foreach ($candidates as $candidate) {
            $candidateCount = (int) $candidate['comparable_completed_task_count'];
            $selectedCount = (int) $selected['comparable_completed_task_count'];

            if ($candidateCount > $selectedCount) {
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
        $workTypeValue = $workType === null
            ? '*'
            : $workType->value;

        $complexityValue = $complexity === null
            ? '*'
            : $complexity->value;

        $projectId = (int) $project->getKey();
        $projectName = (string) $project->getAttribute('name');

        return [
            'machine_label' => sprintf(
                'coder:project:%d:work_type:%s:complexity:%s:fallback:%d',
                $projectId,
                $workTypeValue,
                $complexityValue,
                $fallbackLevel,
            ),
            'human_label' => sprintf(
                'Coder | project/repository %s (#%d) | work_type=%s | complexity=%s',
                $projectName,
                $projectId,
                $workType === null
                    ? 'all (broadened; includes unknown)'
                    : $workType->value,
                $complexity === null
                    ? 'all (broadened; includes unknown)'
                    : $complexity->value,
            ),
            'fallback_level' => $fallbackLevel,
            'fallback_key' => $fallbackKey,
            'filters' => [
                'workflow_role' => AgentRole::Coder->value,
                'project_repository' => [
                    'project_id' => $projectId,
                    'project_name' => $projectName,
                ],
                'work_type' => $workType === null
                    ? null
                    : $workType->value,
                'complexity' => $complexity === null
                    ? null
                    : $complexity->value,
            ],
            'broadened_dimensions' => $broadenedDimensions,
            'includes_unknown_metadata' => [
                'work_type' => $workType === null,
                'complexity' => $complexity === null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function fallbackEvaluation(array $candidate): array
    {
        $score = is_array($candidate['score'] ?? null)
            ? $candidate['score']
            : [];

        return [
            'fallback_level' => (int) $candidate['fallback_level'],
            'fallback_key' => (string) $candidate['fallback_key'],
            'cohort' => $candidate['cohort'],
            'sample' => $this->sampleEvidence(
                score: $score,
                completedTaskCount: (int) $candidate['comparable_completed_task_count'],
                configurationCount: (int) $candidate['configuration_count'],
            ),
            'confidence' => (string) $candidate['confidence'],
        ];
    }

    /**
     * @param  array<string, mixed>  $score
     * @return array<string, int>
     */
    private function sampleEvidence(
        array $score,
        int $completedTaskCount,
        int $configurationCount,
    ): array {
        $cohort = is_array($score['cohort'] ?? null)
            ? $score['cohort']
            : [];

        return [
            'terminal_task_count' => (int) ($cohort['terminal_task_count'] ?? 0),
            'attributed_task_count' => (int) ($cohort['attributed_task_count'] ?? 0),
            'unattributed_task_count' => (int) ($cohort['unattributed_task_count'] ?? 0),
            'comparable_completed_task_count' => $completedTaskCount,
            'configuration_count' => $configurationCount,
        ];
    }

    private function confidenceFor(int $completedTaskCount): string
    {
        if ($completedTaskCount >= self::RecommendationEligibleMinimumCompletedTasks) {
            return self::ConfidenceRecommendationEligible;
        }

        if ($completedTaskCount >= self::PreliminaryMinimumCompletedTasks) {
            return self::ConfidencePreliminary;
        }

        return self::ConfidenceInsufficientData;
    }

    /**
     * @param  list<array<string, mixed>>  $configurationScores
     * @return list<array<string, mixed>>
     */
    private function rankConfigurationScores(array $configurationScores): array
    {
        $scores = array_map(
            function (array $score): array {
                $score['comparable_completed_task_count'] = (int) (
                    $score['successful_task_count'] ?? 0
                );

                return $score;
            },
            $configurationScores,
        );

        usort($scores, function (array $left, array $right): int {
            $scoreComparison = ((float) ($right['composite_score'] ?? 0.0))
                <=> ((float) ($left['composite_score'] ?? 0.0));

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return strcmp(
                $this->configurationSortKey($left),
                $this->configurationSortKey($right),
            );
        });

        return $scores;
    }

    /**
     * @param  list<array<string, mixed>>  $configurationScores
     * @param  array<string, mixed>  $cohort
     * @return array<string, mixed>
     */
    private function recommendation(
        array $configurationScores,
        string $confidence,
        int $completedTaskCount,
        int $scoreVersion,
        array $cohort,
    ): array {
        $evidence = [
            'confidence' => $confidence,
            'score_version' => $scoreVersion,
            'cohort' => [
                'machine_label' => $cohort['machine_label'] ?? null,
                'human_label' => $cohort['human_label'] ?? null,
                'fallback_level' => $cohort['fallback_level'] ?? null,
                'filters' => $cohort['filters'] ?? [],
                'broadened_dimensions' => $cohort['broadened_dimensions'] ?? [],
            ],
            'sample' => [
                'comparable_completed_task_count' => $completedTaskCount,
            ],
            'compared_configurations' => array_map(
                fn (array $score): array => [
                    'configuration_key' => $score['configuration_key'] ?? null,
                    'configuration' => $score['configuration'] ?? null,
                    'sample_count' => (int) ($score['sample_count'] ?? 0),
                    'comparable_completed_task_count' => (int) (
                        $score['successful_task_count'] ?? 0
                    ),
                    'composite_score' => (float) (
                        $score['composite_score'] ?? 0.0
                    ),
                    'component_points' => is_array($score['component_points'] ?? null)
                        ? $score['component_points']
                        : [],
                ],
                $configurationScores,
            ),
        ];

        if ($confidence === self::ConfidenceInsufficientData) {
            return [
                ...$evidence,
                'eligible' => false,
                'leading_configuration' => null,
                'reason' => sprintf(
                    'Insufficient data: %d comparable completed Tasks; at least %d are required for a preliminary comparison and %d for an eligible recommendation.',
                    $completedTaskCount,
                    self::PreliminaryMinimumCompletedTasks,
                    self::RecommendationEligibleMinimumCompletedTasks,
                ),
            ];
        }

        if (count($configurationScores) < 2) {
            return [
                ...$evidence,
                'eligible' => false,
                'leading_configuration' => null,
                'reason' => 'No comparative leader: the selected cohort contains fewer than two scorable harness/model/reasoning configurations.',
            ];
        }

        $leader = $configurationScores[0];
        $runnerUp = $configurationScores[1];

        $leaderScore = (float) ($leader['composite_score'] ?? 0.0);
        $runnerUpScore = (float) ($runnerUp['composite_score'] ?? 0.0);

        if (abs($leaderScore - $runnerUpScore) <= 0.00005) {
            return [
                ...$evidence,
                'eligible' => false,
                'leading_configuration' => null,
                'reason' => sprintf(
                    'No leader: the top configurations are tied at %.4f composite points under score version %d.',
                    $leaderScore,
                    $scoreVersion,
                ),
            ];
        }

        $componentAdvantage = $this->strongestComponentAdvantage(
            $leader,
            $runnerUp,
        );

        $leaderConfiguration = is_array($leader['configuration'] ?? null)
            ? $leader['configuration']
            : [];

        $reason = sprintf(
            '%s leads %s by %.4f composite points',
            $this->configurationLabel($leader),
            $this->configurationLabel($runnerUp),
            $leaderScore - $runnerUpScore,
        );

        if ($componentAdvantage !== null) {
            $reason .= sprintf(
                '; largest positive component-point advantage is %s (+%.4f).',
                $componentAdvantage['component'],
                $componentAdvantage['delta'],
            );
        } else {
            $reason .= '.';
        }

        return [
            ...$evidence,
            'eligible' => $confidence === self::ConfidenceRecommendationEligible,
            'leading_configuration' => [
                'configuration_key' => (string) (
                    $leader['configuration_key'] ?? ''
                ),
                'harness' => is_string($leaderConfiguration['harness'] ?? null)
                    ? $leaderConfiguration['harness']
                    : null,
                'model' => is_string($leaderConfiguration['model'] ?? null)
                    ? $leaderConfiguration['model']
                    : null,
                'reasoning_setting' => is_string(
                    $leaderConfiguration['reasoning_setting'] ?? null,
                )
                    ? $leaderConfiguration['reasoning_setting']
                    : null,
                'sample_count' => (int) ($leader['sample_count'] ?? 0),
                'comparable_completed_task_count' => (int) (
                    $leader['successful_task_count'] ?? 0
                ),
                'composite_score' => $leaderScore,
                'component_points' => is_array($leader['component_points'] ?? null)
                    ? $leader['component_points']
                    : [],
            ],
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $leader
     * @param  array<string, mixed>  $runnerUp
     * @return array{component: string, delta: float}|null
     */
    private function strongestComponentAdvantage(
        array $leader,
        array $runnerUp,
    ): ?array {
        $components = [
            'quality' => [
                $leader['component_points']['quality']['total'] ?? null,
                $runnerUp['component_points']['quality']['total'] ?? null,
            ],
            'reliability' => [
                $leader['component_points']['reliability']['total'] ?? null,
                $runnerUp['component_points']['reliability']['total'] ?? null,
            ],
            'cost_efficiency' => [
                $leader['component_points']['cost_efficiency'] ?? null,
                $runnerUp['component_points']['cost_efficiency'] ?? null,
            ],
            'speed' => [
                $leader['component_points']['speed'] ?? null,
                $runnerUp['component_points']['speed'] ?? null,
            ],
        ];

        $best = null;

        foreach ($components as $name => [$leaderPoints, $runnerUpPoints]) {
            if (! is_numeric($leaderPoints) || ! is_numeric($runnerUpPoints)) {
                continue;
            }

            $delta = round(
                (float) $leaderPoints - (float) $runnerUpPoints,
                4,
            );

            if ($delta <= 0.0) {
                continue;
            }

            if ($best === null || $delta > $best['delta']) {
                $best = [
                    'component' => $name,
                    'delta' => $delta,
                ];
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $score
     */
    private function configurationSortKey(array $score): string
    {
        $configuration = is_array($score['configuration'] ?? null)
            ? $score['configuration']
            : [];

        return implode("\0", [
            is_string($configuration['harness'] ?? null)
                ? $configuration['harness']
                : '',
            is_string($configuration['model'] ?? null)
                ? $configuration['model']
                : '',
            is_string($configuration['reasoning_setting'] ?? null)
                ? $configuration['reasoning_setting']
                : '',
            is_string($score['configuration_key'] ?? null)
                ? $score['configuration_key']
                : '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $score
     */
    private function configurationLabel(array $score): string
    {
        $configuration = is_array($score['configuration'] ?? null)
            ? $score['configuration']
            : [];

        return sprintf(
            '%s / %s / %s',
            is_string($configuration['harness'] ?? null)
                ? $configuration['harness']
                : 'unknown_harness',
            is_string($configuration['model'] ?? null)
                ? $configuration['model']
                : 'provider_default_model',
            is_string($configuration['reasoning_setting'] ?? null)
                ? $configuration['reasoning_setting']
                : 'provider_default_reasoning',
        );
    }

    /**
     * Convert an unknown analytics payload value into a statically safe list of
     * record arrays. Invalid/non-array entries are ignored instead of guessed.
     *
     * @return list<array<string, mixed>>
     */
    private function recordList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $records = [];

        foreach ($value as $record) {
            if (! is_array($record)) {
                continue;
            }

            $records[] = $record;
        }

        return $records;
    }
}
