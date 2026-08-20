<?php

namespace App\Http\Controllers;

use App\AgentHarness;
use App\AgentRole;
use App\Models\Project;
use App\Models\Task;
use App\Services\CoderHarnessComparableCohortScorecards;
use App\Services\ReviewerHarnessDiagnostics;
use App\TaskComplexity;
use App\TaskStatus;
use App\TaskWorkType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class HarnessScorecardController extends Controller
{
    private const string ProviderDefaultFilter = '__provider_default__';

    /**
     * @var list<string>
     */
    private const array TerminalTaskStatuses = [
        TaskStatus::Done->value,
        TaskStatus::Failed->value,
        TaskStatus::Blocked->value,
    ];

    public function index(
        Request $request,
        CoderHarnessComparableCohortScorecards $coderScorecards,
        ReviewerHarnessDiagnostics $reviewerDiagnostics,
    ): Response {
        $validated = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'role' => [
                'nullable',
                Rule::in([
                    'all',
                    AgentRole::Coder->value,
                    AgentRole::Reviewer->value,
                ]),
            ],
            'work_type' => ['nullable', Rule::enum(TaskWorkType::class)],
            'complexity' => ['nullable', Rule::enum(TaskComplexity::class)],
            'harness' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:255'],
            'reasoning_setting' => ['nullable', 'string', 'max:100'],
            'confidence' => [
                'nullable',
                Rule::in([
                    'all',
                    CoderHarnessComparableCohortScorecards::ConfidenceInsufficientData,
                    CoderHarnessComparableCohortScorecards::ConfidencePreliminary,
                    CoderHarnessComparableCohortScorecards::ConfidenceRecommendationEligible,
                ]),
            ],
            'cohort' => [
                'nullable',
                Rule::in(['all', 'exact', 'broadened']),
            ],
        ]);

        $projects = Project::query()
            ->select(['id', 'name', 'path'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $requestedProjectId = $this->integerValue(
            $validated['project_id'] ?? null,
        );

        $project = $requestedProjectId === null
            ? $projects->first()
            : $projects->firstWhere('id', $requestedProjectId);

        $role = $this->validatedString($validated['role'] ?? null) ?? 'all';
        $confidenceFilter = $this->validatedString(
            $validated['confidence'] ?? null,
        ) ?? 'all';
        $cohortFilter = $this->validatedString(
            $validated['cohort'] ?? null,
        ) ?? 'all';

        if (! $project instanceof Project) {
            return Inertia::render('harness-scorecards/index', [
                'projects' => [],
                'selected_project' => null,
                'filters' => [
                    'project_id' => null,
                    'role' => $role,
                    'work_type' => TaskWorkType::Feature->value,
                    'complexity' => TaskComplexity::Medium->value,
                    'harness' => '',
                    'model' => '',
                    'reasoning_setting' => '',
                    'confidence' => $confidenceFilter,
                    'cohort' => $cohortFilter,
                ],
                'filter_options' => $this->filterOptions(null, null),
                'coder_scorecard' => null,
                'reviewer_diagnostics' => null,
                'links' => [
                    'self' => route('harness-scorecards.index'),
                    'agent_configuration' => null,
                ],
            ]);
        }

        $tasks = $project->tasks()
            ->with([
                'attempts',
                'reviews.findings',
                'runs',
                'auditEvents',
            ])
            ->orderBy('id')
            ->get();

        [$defaultWorkType, $defaultComplexity] = $this->defaultDimensions(
            $tasks,
        );

        $workType = isset($validated['work_type'])
            ? TaskWorkType::from((string) $validated['work_type'])
            : $defaultWorkType;

        $complexity = isset($validated['complexity'])
            ? TaskComplexity::from((string) $validated['complexity'])
            : $defaultComplexity;

        $configurationFilters = [
            'harness' => $this->configurationFilter(
                $validated['harness'] ?? null,
            ),
            'model' => $this->configurationFilter(
                $validated['model'] ?? null,
            ),
            'reasoning_setting' => $this->configurationFilter(
                $validated['reasoning_setting'] ?? null,
            ),
        ];

        $coderResult = $role === AgentRole::Reviewer->value
            ? null
            : $coderScorecards->calculate(
                project: $project,
                tasks: $tasks,
                workType: $workType,
                complexity: $complexity,
            );

        $reviewerResult = $role === AgentRole::Coder->value
            ? null
            : $reviewerDiagnostics->calculate(
                project: $project,
                tasks: $tasks,
                workType: $workType,
                complexity: $complexity,
            );

        return Inertia::render('harness-scorecards/index', [
            'projects' => $projects
                ->map(fn (Project $availableProject): array => [
                    'id' => (int) $availableProject->getKey(),
                    'name' => (string) $availableProject->getAttribute('name'),
                    'path' => (string) $availableProject->getAttribute('path'),
                ])
                ->values()
                ->all(),
            'selected_project' => [
                'id' => (int) $project->getKey(),
                'name' => (string) $project->getAttribute('name'),
                'path' => (string) $project->getAttribute('path'),
            ],
            'filters' => [
                'project_id' => (int) $project->getKey(),
                'role' => $role,
                'work_type' => $workType->value,
                'complexity' => $complexity->value,
                'harness' => $configurationFilters['harness'] ?? '',
                'model' => $configurationFilters['model'] ?? '',
                'reasoning_setting' => $configurationFilters['reasoning_setting'] ?? '',
                'confidence' => $confidenceFilter,
                'cohort' => $cohortFilter,
            ],
            'filter_options' => $this->filterOptions(
                $coderResult,
                $reviewerResult,
            ),
            'coder_scorecard' => $coderResult === null
                ? null
                : $this->coderPayload(
                    scorecard: $coderResult,
                    configurationFilters: $configurationFilters,
                    confidenceFilter: $confidenceFilter,
                    cohortFilter: $cohortFilter,
                ),
            'reviewer_diagnostics' => $reviewerResult === null
                ? null
                : $this->reviewerPayload(
                    diagnostics: $reviewerResult,
                    configurationFilters: $configurationFilters,
                    cohortFilter: $cohortFilter,
                ),
            'links' => [
                'self' => route('harness-scorecards.index'),
                'agent_configuration' => route('projects.show', [
                    'project' => $project,
                    'tab' => 'agents',
                ]),
            ],
        ]);
    }

    /**
     * @param  iterable<int, Task>  $tasks
     * @return array{TaskWorkType, TaskComplexity}
     */
    private function defaultDimensions(iterable $tasks): array
    {
        /**
         * @var array<string, array{
         *     count: int,
         *     work_type: TaskWorkType,
         *     complexity: TaskComplexity
         * }> $pairs
         */
        $pairs = [];

        foreach ($tasks as $task) {
            $status = $this->validatedString(
                $task->getRawOriginal('status'),
            );

            if (
                $status === null
                || ! in_array($status, self::TerminalTaskStatuses, true)
            ) {
                continue;
            }

            $workTypeValue = $this->validatedString(
                $task->getRawOriginal('work_type'),
            );
            $complexityValue = $this->validatedString(
                $task->getRawOriginal('complexity'),
            );

            if ($workTypeValue === null || $complexityValue === null) {
                continue;
            }

            $workType = TaskWorkType::tryFrom($workTypeValue);
            $complexity = TaskComplexity::tryFrom($complexityValue);

            if ($workType === null || $complexity === null) {
                continue;
            }

            $key = $workType->value.':'.$complexity->value;

            if (! isset($pairs[$key])) {
                $pairs[$key] = [
                    'count' => 0,
                    'work_type' => $workType,
                    'complexity' => $complexity,
                ];
            }

            $pairs[$key]['count']++;
        }

        if ($pairs === []) {
            return [
                TaskWorkType::Feature,
                TaskComplexity::Medium,
            ];
        }

        $ranked = array_values($pairs);

        usort(
            $ranked,
            function (array $left, array $right): int {
                $countComparison = $right['count'] <=> $left['count'];

                if ($countComparison !== 0) {
                    return $countComparison;
                }

                $workTypeComparison = $left['work_type']->value
                    <=> $right['work_type']->value;

                if ($workTypeComparison !== 0) {
                    return $workTypeComparison;
                }

                return $left['complexity']->value
                    <=> $right['complexity']->value;
            },
        );

        return [
            $ranked[0]['work_type'],
            $ranked[0]['complexity'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $coderResult
     * @param  array<string, mixed>|null  $reviewerResult
     * @return array<string, mixed>
     */
    private function filterOptions(
        ?array $coderResult,
        ?array $reviewerResult,
    ): array {
        $records = [
            ...$this->recordList(
                $coderResult['configuration_scores'] ?? null,
            ),
            ...$this->recordList(
                $reviewerResult['configuration_diagnostics'] ?? null,
            ),
        ];

        /** @var array<string, string> $models */
        $models = [];
        /** @var array<string, string> $reasoningSettings */
        $reasoningSettings = [];

        foreach ($records as $record) {
            $configuration = is_array($record['configuration'] ?? null)
                ? $record['configuration']
                : [];

            $model = $this->validatedString(
                $configuration['model'] ?? null,
            );
            $reasoning = $this->validatedString(
                $configuration['reasoning_setting'] ?? null,
            );

            $modelValue = $model ?? self::ProviderDefaultFilter;
            $reasoningValue = $reasoning ?? self::ProviderDefaultFilter;

            $models[$modelValue] = $model ?? 'Provider default';
            $reasoningSettings[$reasoningValue] = $reasoning
                ?? 'Provider default';
        }

        ksort($models);
        ksort($reasoningSettings);

        return [
            'roles' => [
                ['value' => 'all', 'label' => 'Coder + Reviewer'],
                ['value' => AgentRole::Coder->value, 'label' => 'Coder'],
                ['value' => AgentRole::Reviewer->value, 'label' => 'Reviewer'],
            ],
            'work_types' => array_map(
                fn (TaskWorkType $workType): array => [
                    'value' => $workType->value,
                    'label' => $this->humanize($workType->value),
                ],
                TaskWorkType::cases(),
            ),
            'complexities' => array_map(
                fn (TaskComplexity $complexity): array => [
                    'value' => $complexity->value,
                    'label' => $this->humanize($complexity->value),
                ],
                TaskComplexity::cases(),
            ),
            'harnesses' => array_map(
                fn (AgentHarness $harness): array => [
                    'value' => $harness->value,
                    'label' => $this->humanize($harness->value),
                ],
                AgentHarness::cases(),
            ),
            'models' => $this->associativeOptions($models),
            'reasoning_settings' => $this->associativeOptions(
                $reasoningSettings,
            ),
            'confidences' => [
                [
                    'value' => 'all',
                    'label' => 'All confidence levels',
                ],
                [
                    'value' => CoderHarnessComparableCohortScorecards::ConfidenceInsufficientData,
                    'label' => 'Insufficient data',
                ],
                [
                    'value' => CoderHarnessComparableCohortScorecards::ConfidencePreliminary,
                    'label' => 'Preliminary',
                ],
                [
                    'value' => CoderHarnessComparableCohortScorecards::ConfidenceRecommendationEligible,
                    'label' => 'Recommendation eligible',
                ],
            ],
            'cohorts' => [
                ['value' => 'all', 'label' => 'Exact + broadened'],
                ['value' => 'exact', 'label' => 'Exact cohort only'],
                ['value' => 'broadened', 'label' => 'Broadened cohorts only'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $scorecard
     * @param  array<string, string|null>  $configurationFilters
     * @return array<string, mixed>
     */
    private function coderPayload(
        array $scorecard,
        array $configurationFilters,
        string $confidenceFilter,
        string $cohortFilter,
    ): array {
        $allConfigurations = $this->recordList(
            $scorecard['configuration_scores'] ?? null,
        );

        $visibleConfigurations = $this->filteredConfigurations(
            $allConfigurations,
            $configurationFilters,
        );

        $selectedCohort = is_array($scorecard['selected_cohort'] ?? null)
            ? $scorecard['selected_cohort']
            : [];

        $confidence = is_array($scorecard['confidence'] ?? null)
            ? $scorecard['confidence']
            : [];

        $confidenceLevel = $this->validatedString(
            $confidence['level'] ?? null,
        );

        return [
            'schema_version' => (int) ($scorecard['schema_version'] ?? 0),
            'score_version' => (int) ($scorecard['score_version'] ?? 0),
            'fallback_policy' => is_array(
                $scorecard['fallback_policy'] ?? null,
            ) ? $scorecard['fallback_policy'] : [],
            'recommendation_policy' => is_array(
                $scorecard['recommendation_policy'] ?? null,
            ) ? $scorecard['recommendation_policy'] : [],
            'requested_cohort' => is_array(
                $scorecard['requested_cohort'] ?? null,
            ) ? $scorecard['requested_cohort'] : [],
            'selected_cohort' => $selectedCohort,
            'fallback_evaluations' => $this->recordList(
                $scorecard['fallback_evaluations'] ?? null,
            ),
            'sample' => is_array($scorecard['sample'] ?? null)
                ? $scorecard['sample']
                : [],
            'confidence' => $confidence,
            'methodology' => is_array($scorecard['methodology'] ?? null)
                ? $scorecard['methodology']
                : [],
            'reference' => is_array($scorecard['reference'] ?? null)
                ? $scorecard['reference']
                : [],
            'configuration_scores' => $visibleConfigurations,
            'configuration_count_total' => count($allConfigurations),
            'configuration_count_visible' => count($visibleConfigurations),
            'recommendation' => is_array(
                $scorecard['recommendation'] ?? null,
            ) ? $scorecard['recommendation'] : null,
            'matches_filters' => $this->confidenceMatches(
                $confidenceLevel,
                $confidenceFilter,
            ) && $this->cohortMatches(
                $selectedCohort,
                $cohortFilter,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @param  array<string, string|null>  $configurationFilters
     * @return array<string, mixed>
     */
    private function reviewerPayload(
        array $diagnostics,
        array $configurationFilters,
        string $cohortFilter,
    ): array {
        $allConfigurations = $this->recordList(
            $diagnostics['configuration_diagnostics'] ?? null,
        );

        $visibleConfigurations = $this->filteredConfigurations(
            $allConfigurations,
            $configurationFilters,
        );

        $selectedCohort = is_array(
            $diagnostics['selected_cohort'] ?? null,
        ) ? $diagnostics['selected_cohort'] : [];

        $followThrough = is_array(
            $diagnostics['actionable_finding_follow_through'] ?? null,
        ) ? $diagnostics['actionable_finding_follow_through'] : [];

        unset($followThrough['evidence']);

        return [
            'schema_version' => (int) (
                $diagnostics['schema_version'] ?? 0
            ),
            'methodology_version' => $this->validatedString(
                $diagnostics['methodology_version'] ?? null,
            ),
            'methodology' => is_array(
                $diagnostics['methodology'] ?? null,
            ) ? $diagnostics['methodology'] : [],
            'fallback_policy' => is_array(
                $diagnostics['fallback_policy'] ?? null,
            ) ? $diagnostics['fallback_policy'] : [],
            'requested_cohort' => is_array(
                $diagnostics['requested_cohort'] ?? null,
            ) ? $diagnostics['requested_cohort'] : [],
            'selected_cohort' => $selectedCohort,
            'fallback_evaluations' => $this->recordList(
                $diagnostics['fallback_evaluations'] ?? null,
            ),
            'sample' => is_array($diagnostics['sample'] ?? null)
                ? $diagnostics['sample']
                : [],
            'rates' => is_array($diagnostics['rates'] ?? null)
                ? $diagnostics['rates']
                : [],
            'operational_failure_reasons' => is_array(
                $diagnostics['operational_failure_reasons'] ?? null,
            ) ? $diagnostics['operational_failure_reasons'] : [],
            'configuration_diagnostics' => $visibleConfigurations,
            'configuration_count_total' => count($allConfigurations),
            'configuration_count_visible' => count($visibleConfigurations),
            'approval_consistency' => is_array(
                $diagnostics['approval_consistency'] ?? null,
            ) ? $diagnostics['approval_consistency'] : [],
            'codex_claude_divergence' => is_array(
                $diagnostics['codex_claude_divergence'] ?? null,
            ) ? $diagnostics['codex_claude_divergence'] : [],
            'actionable_finding_follow_through' => $followThrough,
            'recommendation_policy' => is_array(
                $diagnostics['recommendation_policy'] ?? null,
            ) ? $diagnostics['recommendation_policy'] : [],
            'matches_filters' => $this->cohortMatches(
                $selectedCohort,
                $cohortFilter,
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, string|null>  $filters
     * @return list<array<string, mixed>>
     */
    private function filteredConfigurations(
        array $records,
        array $filters,
    ): array {
        return array_values(array_filter(
            $records,
            function (array $record) use ($filters): bool {
                $configuration = is_array(
                    $record['configuration'] ?? null,
                ) ? $record['configuration'] : [];

                return $this->configurationValueMatches(
                    $configuration['harness'] ?? null,
                    $filters['harness'] ?? null,
                )
                    && $this->configurationValueMatches(
                        $configuration['model'] ?? null,
                        $filters['model'] ?? null,
                    )
                    && $this->configurationValueMatches(
                        $configuration['reasoning_setting'] ?? null,
                        $filters['reasoning_setting'] ?? null,
                    );
            },
        ));
    }

    private function configurationValueMatches(
        mixed $actualValue,
        ?string $filter,
    ): bool {
        if ($filter === null) {
            return true;
        }

        $actual = $this->validatedString($actualValue);

        if ($filter === self::ProviderDefaultFilter) {
            return $actual === null;
        }

        return $actual === $filter;
    }

    /**
     * @param  array<string, mixed>  $cohort
     */
    private function cohortMatches(
        array $cohort,
        string $filter,
    ): bool {
        if ($filter === 'all') {
            return true;
        }

        $fallbackLevel = $this->integerValue(
            $cohort['fallback_level'] ?? null,
        );

        if ($fallbackLevel === null) {
            return false;
        }

        return match ($filter) {
            'exact' => $fallbackLevel === 0,
            'broadened' => $fallbackLevel > 0,
            default => true,
        };
    }

    private function confidenceMatches(
        ?string $confidence,
        string $filter,
    ): bool {
        return $filter === 'all' || $confidence === $filter;
    }

    private function configurationFilter(mixed $value): ?string
    {
        $value = $this->validatedString($value);

        return $value === null || $value === 'all'
            ? null
            : $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recordList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $records = [];

        foreach ($value as $record) {
            if (is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param  array<string, string>  $values
     * @return list<array{value: string, label: string}>
     */
    private function associativeOptions(array $values): array
    {
        $options = [];

        foreach ($values as $value => $label) {
            $options[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $options;
    }

    private function validatedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
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

    private function humanize(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}
