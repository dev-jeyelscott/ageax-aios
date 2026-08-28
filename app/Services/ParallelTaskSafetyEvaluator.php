<?php

namespace App\Services;

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Task;
use App\ParallelTaskSafety;
use App\TaskStatus;
use Illuminate\Support\Str;

final class ParallelTaskSafetyEvaluator
{
    private const int SCHEMA_VERSION = 1;

    private const int MAX_PATHS_PER_TASK = 32;

    private const int MAX_PATH_LENGTH = 255;

    private const int MAX_DEPENDENCY_NODES = 128;

    /**
     * Create the deterministic evaluator with existing audit and repository safety boundaries.
     */
    public function __construct(
        private AuditLogger $audit,
        private CoderRepositoryGuard $repositoryGuard,
    ) {}

    /**
     * Evaluate one candidate Task against one concurrent Task using deterministic AIOS-owned evidence.
     *
     * @return array{decision: ParallelTaskSafety, reasons: list<string>}
     */
    public function evaluate(Task $candidate, Task $concurrentTask): array
    {
        $candidate->loadMissing('project');
        $concurrentTask->loadMissing('project');

        $unsafeReasons = [];
        $unknownReasons = [];

        $candidateStatus = $this->taskStatus($candidate);
        $concurrentStatus = $this->taskStatus($concurrentTask);

        $candidatePaths = $this->normalizedRelevantPaths($candidate);
        $concurrentPaths = $this->normalizedRelevantPaths($concurrentTask);

        $candidateResourceClasses = $candidatePaths === null
            ? []
            : $this->sharedResourceClasses($candidatePaths);

        $concurrentResourceClasses = $concurrentPaths === null
            ? []
            : $this->sharedResourceClasses($concurrentPaths);

        $candidateRiskClasses = $candidatePaths === null
            ? []
            : $this->serialRiskClasses($candidatePaths);

        $concurrentRiskClasses = $concurrentPaths === null
            ? []
            : $this->serialRiskClasses($concurrentPaths);

        if ((int) $candidate->getKey() === (int) $concurrentTask->getKey()) {
            $unsafeReasons[] = 'same_task';
        }

        if ((int) $candidate->project_id !== (int) $concurrentTask->project_id) {
            $unsafeReasons[] = 'different_projects';
        }

        if ($candidate->phase_id === null || $concurrentTask->phase_id === null) {
            $unknownReasons[] = 'phase_scope_unknown';
        } elseif ((int) $candidate->phase_id !== (int) $concurrentTask->phase_id) {
            $unsafeReasons[] = 'different_phases';
        }

        foreach ([$candidateStatus, $concurrentStatus] as $status) {
            if ($status === null) {
                $unknownReasons[] = 'task_status_unknown';

                continue;
            }

            if (! $this->isParallelCoderStatus($status)) {
                $unsafeReasons[] = 'task_status_not_coder_parallel_eligible';
            }
        }

        if ($candidatePaths === null || $concurrentPaths === null) {
            $unknownReasons[] = 'relevant_paths_unknown';
        } else {
            if (array_intersect($candidatePaths, $concurrentPaths) !== []) {
                $unsafeReasons[] = 'relevant_path_overlap';
            }

            if (
                array_intersect(
                    $candidateResourceClasses,
                    $concurrentResourceClasses,
                ) !== []
            ) {
                $unsafeReasons[] = 'shared_resource_class_overlap';
            }

            if (
                $candidateRiskClasses !== []
                || $concurrentRiskClasses !== []
            ) {
                $unsafeReasons[] = 'high_risk_global_change';
            }
        }

        if ((int) $candidate->project_id === (int) $concurrentTask->project_id) {
            foreach ([$candidate, $concurrentTask] as $task) {
                foreach ($task->dependencies()->get() as $dependency) {
                    if ((int) $dependency->project_id !== (int) $task->project_id) {
                        $unsafeReasons[] = 'cross_project_dependency';

                        continue;
                    }

                    $dependencyStatus = $this->taskStatus($dependency);

                    if ($dependencyStatus === null) {
                        $unknownReasons[] = 'dependency_status_unknown';

                        continue;
                    }

                    $samePhase = $task->phase_id !== null
                        && (int) $dependency->phase_id === (int) $task->phase_id;

                    if (! $dependencyStatus->satisfiesDependency($samePhase)) {
                        $unsafeReasons[] = 'unsatisfied_dependency';
                    }
                }
            }

            $candidateDependsOnConcurrent = $this->dependsOn(
                $candidate,
                $concurrentTask,
            );

            $concurrentDependsOnCandidate = $this->dependsOn(
                $concurrentTask,
                $candidate,
            );

            if (
                $candidateDependsOnConcurrent === null
                || $concurrentDependsOnCandidate === null
            ) {
                $unknownReasons[] = 'dependency_graph_unknown';
            } elseif (
                $candidateDependsOnConcurrent
                || $concurrentDependsOnCandidate
            ) {
                $unsafeReasons[] = 'dependency_related_tasks';
            }
        }

        $candidateExecution = $this->activeExecutionEvidence(
            $candidate,
            $candidateStatus,
        );

        $concurrentExecution = $this->activeExecutionEvidence(
            $concurrentTask,
            $concurrentStatus,
        );

        $unsafeReasons = [
            ...$unsafeReasons,
            ...$candidateExecution['unsafe_reasons'],
            ...$concurrentExecution['unsafe_reasons'],
        ];

        $unknownReasons = [
            ...$unknownReasons,
            ...$candidateExecution['unknown_reasons'],
            ...$concurrentExecution['unknown_reasons'],
        ];

        $candidateRepository = $this->repositoryEvidence($candidate);
        $concurrentRepository = $this->repositoryEvidence($concurrentTask);

        if (
            $this->repositorySnapshotsDiffer(
                $candidateRepository['evidence'],
                $concurrentRepository['evidence'],
            )
        ) {
            $unknownReasons[] = 'repository_snapshot_changed';
        }

        $unsafeReasons = [
            ...$unsafeReasons,
            ...$candidateRepository['unsafe_reasons'],
            ...$concurrentRepository['unsafe_reasons'],
        ];

        $unknownReasons = [
            ...$unknownReasons,
            ...$candidateRepository['unknown_reasons'],
            ...$concurrentRepository['unknown_reasons'],
        ];

        $unsafeReasons = $this->orderedUnique($unsafeReasons);
        $unknownReasons = $this->orderedUnique($unknownReasons);

        $decision = match (true) {
            $unsafeReasons !== [] => ParallelTaskSafety::Unsafe,
            $unknownReasons !== [] => ParallelTaskSafety::Unknown,
            default => ParallelTaskSafety::Safe,
        };

        $reasons = $decision === ParallelTaskSafety::Safe
            ? ['independent_task_scope']
            : $this->orderedUnique([
                ...$unsafeReasons,
                ...$unknownReasons,
            ]);

        $this->audit->record(
            'task.parallel_safety_evaluated',
            [
                'schema_version' => self::SCHEMA_VERSION,
                'decision' => $decision->value,
                'reason_codes' => $reasons,
                'candidate' => [
                    'task_id' => (int) $candidate->getKey(),
                    'phase_id' => $candidate->phase_id === null
                        ? null
                        : (int) $candidate->phase_id,
                    'status' => $candidateStatus?->value,
                    'relevant_paths' => $candidatePaths ?? [],
                    'resource_classes' => $candidateResourceClasses,
                    'serial_risk_classes' => $candidateRiskClasses,
                    'active_execution' => $candidateExecution['evidence'],
                    'repository' => $candidateRepository['evidence'],
                ],
                'concurrent_task' => [
                    'task_id' => (int) $concurrentTask->getKey(),
                    'phase_id' => $concurrentTask->phase_id === null
                        ? null
                        : (int) $concurrentTask->phase_id,
                    'status' => $concurrentStatus?->value,
                    'relevant_paths' => $concurrentPaths ?? [],
                    'resource_classes' => $concurrentResourceClasses,
                    'serial_risk_classes' => $concurrentRiskClasses,
                    'active_execution' => $concurrentExecution['evidence'],
                    'repository' => $concurrentRepository['evidence'],
                ],
            ],
            $candidate->project,
            $candidate,
        );

        return [
            'decision' => $decision,
            'reasons' => $reasons,
        ];
    }

    /**
     * Resolve one Task status without allowing malformed durable data to make concurrency optimistic.
     */
    private function taskStatus(Task $task): ?TaskStatus
    {
        return TaskStatus::tryFrom(
            (string) $task->getRawOriginal('status'),
        );
    }

    /**
     * Determine whether a Task status can participate in a Coder parallel-safety decision.
     */
    private function isParallelCoderStatus(TaskStatus $status): bool
    {
        return $status->isCoderClaimable()
            || in_array(
                $status,
                [
                    TaskStatus::Coding,
                    TaskStatus::Validating,
                ],
                true,
            );
    }

    /**
     * Determine whether one Task transitively depends on another within a bounded project graph.
     */
    private function dependsOn(Task $task, Task $target): ?bool
    {
        $visited = [];
        $frontier = [$task];

        while ($frontier !== []) {
            /** @var Task $current */
            $current = array_shift($frontier);

            foreach (
                $current->dependencies()
                    ->orderBy('tasks.id')
                    ->get() as $dependency
            ) {
                if ((int) $dependency->project_id !== (int) $task->project_id) {
                    return null;
                }

                $dependencyId = (int) $dependency->getKey();

                if ($dependencyId === (int) $target->getKey()) {
                    return true;
                }

                if (isset($visited[$dependencyId])) {
                    continue;
                }

                $visited[$dependencyId] = true;

                if (count($visited) > self::MAX_DEPENDENCY_NODES) {
                    return null;
                }

                $frontier[] = $dependency;
            }
        }

        return false;
    }

    /**
     * Normalize bounded file-level relevant paths, returning null when impact cannot be known safely.
     *
     * @return list<string>|null
     */
    private function normalizedRelevantPaths(Task $task): ?array
    {
        $paths = $task->getAttribute('relevant_paths');

        if (
            ! is_array($paths)
            || $paths === []
            || count($paths) > self::MAX_PATHS_PER_TASK
        ) {
            return null;
        }

        $normalized = [];

        foreach ($paths as $path) {
            if (! is_string($path)) {
                return null;
            }

            $path = trim($path);

            if (
                $path === ''
                || strlen($path) > self::MAX_PATH_LENGTH
                || Str::startsWith($path, ['/', '\\'])
                || str_contains($path, '\\')
                || preg_match('/[*?\[\]{}]/', $path) === 1
            ) {
                return null;
            }

            $path = preg_replace('#^\./+#', '', $path) ?? $path;
            $segments = explode('/', $path);

            if (
                in_array('..', $segments, true)
                || $this->looksLikeDirectoryScope($path)
            ) {
                return null;
            }

            $normalized[$path] = true;
        }

        $paths = array_keys($normalized);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Detect path declarations that are too broad to prove file-level independence.
     */
    private function looksLikeDirectoryScope(string $path): bool
    {
        if (str_ends_with($path, '/')) {
            return true;
        }

        $basename = basename($path);

        return $basename !== 'artisan'
            && ! str_contains($basename, '.');
    }

    /**
     * Classify repository-wide shared resource surfaces whose overlap must remain serial.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function sharedResourceClasses(array $paths): array
    {
        $classes = [];

        foreach ($paths as $path) {
            if (Str::startsWith($path, 'routes/')) {
                $classes['routing'] = true;
            }

            if (Str::startsWith($path, 'app/Providers/')) {
                $classes['service_container'] = true;
            }

            if ($path === 'resources/css/app.css') {
                $classes['global_styles'] = true;
            }

            if (Str::startsWith($path, 'resources/js/components/ui/')) {
                $classes['shared_ui'] = true;
            }

            if (
                in_array(
                    $path,
                    [
                        'tests/Pest.php',
                        'tests/TestCase.php',
                    ],
                    true,
                )
            ) {
                $classes['test_bootstrap'] = true;
            }
        }

        $classes = array_keys($classes);
        sort($classes, SORT_STRING);

        return $classes;
    }

    /**
     * Classify schema, configuration, dependency, generated, and build surfaces that always remain serial.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function serialRiskClasses(array $paths): array
    {
        $classes = [];

        foreach ($paths as $path) {
            if (
                Str::startsWith(
                    $path,
                    [
                        'database/migrations/',
                        'database/schema/',
                    ],
                )
            ) {
                $classes['database_schema'] = true;
            }

            if (
                Str::startsWith($path, ['config/', 'bootstrap/'])
                || Str::startsWith($path, '.env')
            ) {
                $classes['global_configuration'] = true;
            }

            if (
                in_array(
                    $path,
                    [
                        'composer.json',
                        'composer.lock',
                        'package.json',
                        'package-lock.json',
                        'pnpm-lock.yaml',
                        'yarn.lock',
                        'bun.lock',
                        'bun.lockb',
                    ],
                    true,
                )
            ) {
                $classes['package_dependencies'] = true;
            }

            if (
                Str::startsWith(
                    $path,
                    [
                        'resources/js/actions/',
                        'resources/js/routes/',
                    ],
                )
            ) {
                $classes['generated_artifacts'] = true;
            }

            if (
                Str::startsWith($path, '.github/workflows/')
                || preg_match(
                    '/^(vite|vitest|eslint|postcss|tailwind)\.config\.[A-Za-z0-9._-]+$/',
                    $path,
                ) === 1
                || preg_match(
                    '/^tsconfig(?:\.[A-Za-z0-9._-]+)?\.json$/',
                    $path,
                ) === 1
                || in_array(
                    $path,
                    [
                        'phpunit.xml',
                        'phpunit.xml.dist',
                        'phpstan.neon',
                        'phpstan.neon.dist',
                        'pint.json',
                    ],
                    true,
                )
            ) {
                $classes['global_build_configuration'] = true;
            }
        }

        $classes = array_keys($classes);
        sort($classes, SORT_STRING);

        return $classes;
    }

    /**
     * Resolve durable active Coder execution evidence for one Task without relying on LLM state.
     *
     * @return array{
     *     unsafe_reasons: list<string>,
     *     unknown_reasons: list<string>,
     *     evidence: array{
     *         task_claimed_active: bool,
     *         running_agent_runs: int
     *     }
     * }
     */
    private function activeExecutionEvidence(
        Task $task,
        ?TaskStatus $status,
    ): array {
        $taskClaimedActive = in_array(
            $status,
            [
                TaskStatus::Coding,
                TaskStatus::Validating,
            ],
            true,
        );

        $runningRuns = AgentRun::query()
            ->where('project_id', $task->project_id)
            ->where('task_id', $task->getKey())
            ->where('role', AgentRole::Coder->value)
            ->where('status', AgentRunStatus::Running->value)
            ->count();

        $unsafeReasons = [];
        $unknownReasons = [];

        if ($runningRuns > 1) {
            $unsafeReasons[] = 'duplicate_active_execution';
        } elseif (($runningRuns === 1) !== $taskClaimedActive) {
            $unknownReasons[] = 'active_execution_state_mismatch';
        }

        return [
            'unsafe_reasons' => $unsafeReasons,
            'unknown_reasons' => $unknownReasons,
            'evidence' => [
                'task_claimed_active' => $taskClaimedActive,
                'running_agent_runs' => $runningRuns,
            ],
        ];
    }

    /**
     * Inspect one Task's current managed repository state for active or uncertain Git integration.
     *
     * @return array{
     *     unsafe_reasons: list<string>,
     *     unknown_reasons: list<string>,
     *     evidence: array{
     *         mode: string,
     *         inspectable: bool,
     *         clean: bool,
     *         head_sha: ?string,
     *         base_sha: ?string
     *     }
     * }
     */
    private function repositoryEvidence(Task $task): array
    {
        $preflight = $this->repositoryGuard->inspect($task);
        $state = $preflight['state'];

        $unsafeReasons = [];
        $unknownReasons = [];

        if (! $state['inspectable']) {
            $unknownReasons[] = 'repository_state_unknown';
        } elseif (! $state['clean']) {
            $unsafeReasons[] = 'repository_has_active_changes';
        }

        if ($preflight['mode'] === 'recovery') {
            $unsafeReasons[] = 'active_git_recovery_state';
        } elseif (! $preflight['allowed']) {
            $unknownReasons[] = 'repository_preflight_unknown';
        }

        return [
            'unsafe_reasons' => $unsafeReasons,
            'unknown_reasons' => $unknownReasons,
            'evidence' => [
                'mode' => $preflight['mode'],
                'inspectable' => $state['inspectable'],
                'clean' => $state['clean'],
                'head_sha' => $state['head_sha'],
                'base_sha' => $state['base_sha'],
            ],
        ];
    }

    /**
     * Detect repository state changing during evaluation so concurrency never relies on a torn Git snapshot.
     *
     * @param  array{
     *     mode: string,
     *     inspectable: bool,
     *     clean: bool,
     *     head_sha: ?string,
     *     base_sha: ?string
     * }  $candidate
     * @param  array{
     *     mode: string,
     *     inspectable: bool,
     *     clean: bool,
     *     head_sha: ?string,
     *     base_sha: ?string
     * }  $concurrent
     */
    private function repositorySnapshotsDiffer(
        array $candidate,
        array $concurrent,
    ): bool {
        return $candidate['inspectable'] !== $concurrent['inspectable']
            || $candidate['clean'] !== $concurrent['clean']
            || $candidate['head_sha'] !== $concurrent['head_sha']
            || $candidate['base_sha'] !== $concurrent['base_sha'];
    }

    /**
     * Return deterministic unique reason codes while preserving evaluation order.
     *
     * @param  list<string>  $reasons
     * @return list<string>
     */
    private function orderedUnique(array $reasons): array
    {
        return array_values(array_unique($reasons));
    }
}
