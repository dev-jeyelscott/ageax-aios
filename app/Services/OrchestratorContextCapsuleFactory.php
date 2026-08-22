<?php

namespace App\Services;

use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Review;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\TaskComplexity;
use App\TaskWorkType;
use LogicException;

final class OrchestratorContextCapsuleFactory
{
    public const int SchemaVersion = 1;

    private const int MaxRuns = 12;

    private const int MaxAttempts = 8;

    private const int MaxFindings = 8;

    private const int MaxRecoveryIncidents = 8;

    private const int MaxKnowledgeCandidates = 8;

    private const int CandidateScanLimit = 100;

    private const int MaxChangedFiles = 24;

    private const int MaxScorecardConfigurations = 8;

    private const int MaxText = 1000;

    /** @var list<string> */
    private const array RetryEventTypes = [
        'review.failed',
        'review.retry_exhausted',
        'task.coder_retry_exhausted',
        'task.contract_drift_detected',
        'task.no_progress_detected',
    ];

    /** Resolve existing global-Agent and scorecard infrastructure. */
    public function __construct(
        private readonly GlobalAgentResolver $globalAgents,
        private readonly CoderHarnessComparableCohortScorecards $scorecards,
    ) {}

    /**
     * Assemble one deterministic, bounded evidence capsule for advisory Orchestrator reasoning.
     *
     * @return array<string, mixed>
     */
    public function make(
        Project $project,
        ?Task $task = null,
        ?RecoveryIncident $recoveryIncident = null,
    ): array {
        $task = $this->validatedTaskScope(
            $project,
            $task,
            $recoveryIncident,
        );

        $orchestrator = $this->globalAgents->forRole(
            AgentRole::Orchestrator,
        );

        $attempts = $this->attempts($task);

        $latestAttempt = $attempts === []
            ? null
            : $attempts[array_key_last($attempts)];

        $review = $this->latestReview($task);

        $runs = $this->runs(
            $project,
            $task,
            $recoveryIncident,
        );

        $retryEvents = $this->retryEvents(
            $project,
            $task,
        );

        $incidents = $this->incidents(
            $project,
            $task,
            $recoveryIncident,
        );

        $knowledge = $this->knowledge(
            $project,
            $task,
            $recoveryIncident,
        );

        $scorecard = $this->scorecard(
            $project,
            $task,
        );

        $payload = [
            'schema_version' => self::SchemaVersion,

            'scope' => $this->scope(
                $project,
                $task,
                $recoveryIncident,
            ),

            'task' => $this->task($task),

            'workflow_state' => $this->workflowState(
                $project,
                $task,
                $recoveryIncident,
            ),

            'current_agent_configuration' => $this->currentAgent(
                $orchestrator,
            ),

            'context_budget_policy' => [
                'schema_version' => ContextBudgetPolicy::SchemaVersion,
                'policy_version' => ContextBudgetPolicy::PolicyVersion,
            ],

            'previous_attempt' => $this->attempt(
                $latestAttempt,
            ),

            'review' => $this->review(
                $review,
            ),

            'review_findings' => $this->reviewFindings(
                $review,
            ),

            'validation_evidence' => $this->validation(
                $latestAttempt,
            ),

            'current_failure_evidence' => $this->currentFailure(
                $retryEvents,
                $latestAttempt,
                $recoveryIncident,
            ),

            'recovery_incident' => $recoveryIncident === null
                ? null
                : $this->incident($recoveryIncident),

            'retrieval_manifest' => $this->manifest(
                $project,
                $task,
                $recoveryIncident,
                $orchestrator,
                $attempts,
                $review,
                $runs,
                $retryEvents,
                $incidents,
                $knowledge,
                $scorecard,
            ),

            'older_history' => [
                'agent_runs' => array_map(
                    $this->run(...),
                    $runs,
                ),

                'scorecard' => $scorecard,

                'attempts' => array_map(
                    fn (TaskAttempt $attempt): array => $this->attempt(
                        $attempt,
                    ) ?? [],
                    $latestAttempt === null
                        ? []
                        : array_slice($attempts, 0, -1),
                ),

                'retry_evidence' => $retryEvents,

                'recovery_incidents' => array_map(
                    $this->incident(...),
                    $incidents,
                ),

                'knowledge_improvements' => array_map(
                    fn (
                        KnowledgeImprovementCandidate $candidate,
                    ): array => $this->candidate(
                        $candidate,
                        $task,
                        $recoveryIncident,
                    ),
                    $knowledge,
                ),
            ],
        ];

        $payload = $this->canonical($payload);

        return [
            ...$payload,
            'capsule_hash' => hash(
                'sha256',
                $this->json($payload),
            ),
        ];
    }

    /**
     * Validate persisted scope and resolve an incident-linked Task without allowing cross-project evidence.
     */
    private function validatedTaskScope(
        Project $project,
        ?Task $task,
        ?RecoveryIncident $recoveryIncident,
    ): ?Task {
        if (! $project->exists) {
            throw new LogicException(
                'Orchestrator evidence Project scope must be persisted.',
            );
        }

        $projectId = (int) $project->id;

        if (
            $task !== null
            && (
                ! $task->exists
                || (int) $task->project_id !== $projectId
            )
        ) {
            throw new LogicException(
                'Orchestrator evidence scope cannot cross the requested Project boundary.',
            );
        }

        if ($recoveryIncident === null) {
            return $task;
        }

        if (
            ! $recoveryIncident->exists
            || (int) $recoveryIncident->project_id !== $projectId
        ) {
            throw new LogicException(
                'Orchestrator evidence scope cannot cross the requested Project boundary.',
            );
        }

        $incidentTask = $recoveryIncident->task_id === null
            ? null
            : Task::query()
                ->where(
                    'project_id',
                    $projectId,
                )
                ->whereKey(
                    $recoveryIncident->task_id,
                )
                ->first();

        if (
            $recoveryIncident->task_id !== null
            && $incidentTask === null
        ) {
            throw new LogicException(
                'Orchestrator RecoveryIncident Task scope is invalid.',
            );
        }

        if (
            $task !== null
            && $incidentTask !== null
            && ! $task->is($incidentTask)
        ) {
            throw new LogicException(
                'Orchestrator Task and RecoveryIncident scope must refer to the same Task.',
            );
        }

        return $task ?? $incidentTask;
    }

    /**
     * Build the stable typed scope identity included in the capsule and manifest.
     *
     * @return array<string, mixed>
     */
    private function scope(
        Project $project,
        ?Task $task,
        ?RecoveryIncident $incident,
    ): array {
        return [
            'project_id' => (int) $project->id,
            'task_id' => $task?->id,
            'recovery_incident_id' => $incident?->id,
        ];
    }

    /**
     * Select only allowlisted workflow-relevant Task metadata.
     *
     * @return array<string, mixed>|null
     */
    private function task(
        ?Task $task,
    ): ?array {
        if ($task === null) {
            return null;
        }

        $task->loadMissing('phase');

        $dependencies = $task->dependencies()
            ->orderBy('tasks.id')
            ->get([
                'tasks.id',
                'tasks.key',
            ]);

        return [
            'id' => (int) $task->id,
            'key' => (string) $task->key,
            'title' => (string) $task->title,
            'objective' => (string) $task->objective,
            'status' => (string) $task->getRawOriginal(
                'status',
            ),
            'phase_id' => $task->phase_id === null
                ? null
                : (int) $task->phase_id,
            'phase_position' => $task->phase?->position === null
                ? null
                : (int) $task->phase->position,
            'position' => (int) $task->position,
            'work_type' => $this->rawString(
                $task->getRawOriginal('work_type'),
            ),
            'complexity' => $this->rawString(
                $task->getRawOriginal('complexity'),
            ),
            'dependencies' => $dependencies
                ->map(
                    fn (Task $dependency): array => [
                        'id' => (int) $dependency->id,
                        'key' => (string) $dependency->key,
                    ],
                )
                ->all(),
        ];
    }

    /**
     * Capture current Laravel-owned workflow state without deriving or mutating transitions.
     *
     * @return array<string, mixed>
     */
    private function workflowState(
        Project $project,
        ?Task $task,
        ?RecoveryIncident $incident,
    ): array {
        return [
            'project_status' => (string) $project->getRawOriginal(
                'status',
            ),
            'task_status' => $task === null
                ? null
                : (string) $task->getRawOriginal('status'),
            'task_claimed_at' => $task?->claimed_at?->toIso8601String(),
            'task_completed_at' => $task?->completed_at?->toIso8601String(),
            'recovery_status' => $incident === null
                ? null
                : (string) $incident->getRawOriginal('status'),
        ];
    }

    /**
     * Capture current persisted Orchestrator configuration without copying raw default context.
     *
     * @return array<string, mixed>
     */
    private function currentAgent(
        Agent $agent,
    ): array {
        return [
            'id' => (int) $agent->id,
            'name' => (string) $agent->name,
            'role' => (string) $agent->getRawOriginal(
                'role',
            ),
            'harness' => (string) $agent->getRawOriginal(
                'harness',
            ),
            'model' => $this->rawString(
                $agent->getRawOriginal('model'),
            ),
            'reasoning_setting' => $this->rawString(
                $agent->getRawOriginal('reasoning_setting'),
            ),
            'default_context_hash' => is_string(
                $agent->default_context,
            )
                ? hash(
                    'sha256',
                    $agent->default_context,
                )
                : null,
            'configuration_version' => (int) $agent->configuration_version,
            'enabled' => (bool) $agent->enabled,
        ];
    }

    /**
     * Read a bounded, deterministically ordered TaskAttempt history for the scoped Task.
     *
     * @return list<TaskAttempt>
     */
    private function attempts(
        ?Task $task,
    ): array {
        if ($task === null) {
            return [];
        }

        return $task->attempts()
            ->orderByDesc('number')
            ->orderByDesc('id')
            ->limit(self::MaxAttempts)
            ->get()
            ->sortBy([
                ['number', 'asc'],
                ['id', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * Convert one TaskAttempt to bounded durable retry and repository evidence.
     *
     * @return array<string, mixed>|null
     */
    private function attempt(
        ?TaskAttempt $attempt,
    ): ?array {
        if ($attempt === null) {
            return null;
        }

        $validation = $this->array(
            $attempt->getAttribute(
                'validation_results',
            ),
        );

        return [
            'id' => (int) $attempt->id,
            'number' => (int) $attempt->number,
            'base_sha' => $this->rawString(
                $attempt->base_sha,
            ),
            'head_sha' => $this->rawString(
                $attempt->head_sha,
            ),
            'commit_sha' => $this->rawString(
                $attempt->commit_sha,
            ),
            'status' => (string) $attempt->status,
            'changed_files' => $this->strings(
                $attempt->changed_files,
            ),
            'no_progress' => $this->noProgress(
                $validation['no_progress'] ?? null,
            ),
            'started_at' => $attempt->started_at?->toIso8601String(),
            'finished_at' => $attempt->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Extract deterministic validation checks and failed evidence from the latest attempt.
     *
     * @return array<string, mixed>|null
     */
    private function validation(
        ?TaskAttempt $attempt,
    ): ?array {
        if ($attempt === null) {
            return null;
        }

        $validation = $this->array(
            $attempt->getAttribute(
                'validation_results',
            ),
        );

        $checks = $this->array(
            $validation['checks'] ?? null,
        );

        ksort(
            $checks,
            SORT_STRING,
        );

        $failed = [];

        foreach ($checks as $name => $passed) {
            if (
                is_string($name)
                && $passed === false
            ) {
                $failed[] = $name;
            }
        }

        sort(
            $failed,
            SORT_STRING,
        );

        $evidence = [];

        foreach (
            $this->array(
                $validation['evidence'] ?? null,
            ) as $name => $item
        ) {
            if (
                ! is_string($name)
                || ! is_array($item)
                || ($item['passed'] ?? true) !== false
            ) {
                continue;
            }

            $evidence[$name] = [
                'passed' => false,
                'name' => $this->rawString(
                    $item['name'] ?? null,
                ),
                'summary' => $this->text(
                    $item['summary'] ?? null,
                ),
                'reason' => $this->text(
                    $item['reason'] ?? null,
                ),
                'exit_code' => is_int(
                    $item['exit_code'] ?? null,
                )
                    ? $item['exit_code']
                    : null,
                'files' => $this->strings(
                    $item['files'] ?? [],
                ),
            ];
        }

        ksort(
            $evidence,
            SORT_STRING,
        );

        return [
            'attempt_id' => (int) $attempt->id,
            'attempt_number' => (int) $attempt->number,
            'passed' => is_bool(
                $validation['passed'] ?? null,
            )
                ? $validation['passed']
                : null,
            'checks' => $checks,
            'failed_checks' => $failed,
            'failed_evidence' => $evidence,
            'task_contract_fingerprint' => is_array(
                $validation['task_contract'] ?? null,
            )
                ? $this->rawString(
                    $validation['task_contract']['fingerprint'] ?? null,
                )
                : null,
        ];
    }

    /** Resolve the newest finalized Review for the scoped Task. */
    private function latestReview(
        ?Task $task,
    ): ?Review {
        return $task?->reviews()
            ->orderByDesc('id')
            ->with('findings')
            ->first();
    }

    /**
     * Convert the latest persisted Review decision to bounded evidence.
     *
     * @return array<string, mixed>|null
     */
    private function review(
        ?Review $review,
    ): ?array {
        if ($review === null) {
            return null;
        }

        return [
            'id' => (int) $review->id,
            'task_attempt_id' => (int) $review->task_attempt_id,
            'status' => (string) $review->getRawOriginal(
                'status',
            ),
            'summary' => $this->text(
                $review->summary,
            ),
            'completed_at' => $review->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Select a bounded deterministic set of structured findings from the current Review.
     *
     * @return list<array<string, mixed>>
     */
    private function reviewFindings(
        ?Review $review,
    ): array {
        if ($review === null) {
            return [];
        }

        return $review->findings
            ->sortBy('id')
            ->take(self::MaxFindings)
            ->map(
                fn ($finding): array => [
                    'id' => (int) $finding->id,
                    'severity' => $this->rawString(
                        $finding->severity,
                    ),
                    'location' => $this->text(
                        $finding->location,
                    ),
                    'current_implementation' => $this->text(
                        $finding->current_implementation,
                    ),
                    'expected_implementation' => $this->text(
                        $finding->expected_implementation,
                    ),
                    'why_incorrect' => $this->text(
                        $finding->why_incorrect,
                    ),
                    'required_fix' => $this->text(
                        $finding->required_fix,
                    ),
                    'verification_requirement' => $this->text(
                        $finding->verification_requirement,
                    ),
                    'implementation_fix_context' => $this->text(
                        $finding->implementation_fix_context,
                    ),
                ],
            )
            ->values()
            ->all();
    }

    /**
     * Query only bounded AgentRuns that intersect the proven project, Task, or incident scope.
     *
     * @return list<AgentRun>
     */
    private function runs(
        Project $project,
        ?Task $task,
        ?RecoveryIncident $incident,
    ): array {
        $query = AgentRun::query()
            ->where(
                'project_id',
                $project->id,
            );

        if ($task !== null) {
            $query->where(
                function ($query) use (
                    $task,
                    $incident,
                ): void {
                    $query->where(
                        'task_id',
                        $task->id,
                    );

                    if ($incident !== null) {
                        $query->orWhere(
                            'recovery_incident_id',
                            $incident->id,
                        );

                        if (
                            $incident->source_agent_run_id
                            !== null
                        ) {
                            $query->orWhereKey(
                                $incident->source_agent_run_id,
                            );
                        }
                    }
                },
            );
        } elseif ($incident !== null) {
            $query->where(
                function ($query) use (
                    $incident,
                ): void {
                    $query->where(
                        'recovery_incident_id',
                        $incident->id,
                    );

                    if (
                        $incident->source_agent_run_id
                        !== null
                    ) {
                        $query->orWhereKey(
                            $incident->source_agent_run_id,
                        );
                    }
                },
            );
        }

        return $query
            ->orderByDesc('id')
            ->limit(self::MaxRuns)
            ->get()
            ->sortBy('id')
            ->values()
            ->all();
    }

    /**
     * Convert an AgentRun to immutable configuration, outcome, hash, and budget identities only.
     *
     * @return array<string, mixed>
     */
    private function run(
        AgentRun $run,
    ): array {
        return [
            'id' => (int) $run->id,
            'task_id' => $run->task_id === null
                ? null
                : (int) $run->task_id,
            'recovery_incident_id' => $run->recovery_incident_id === null
                ? null
                : (int) $run->recovery_incident_id,
            'agent_id' => $run->agent_id === null
                ? null
                : (int) $run->agent_id,
            'role' => (string) $run->getRawOriginal(
                'role',
            ),
            'harness' => $this->rawString(
                $run->harness,
            ),
            'status' => (string) $run->getRawOriginal(
                'status',
            ),
            'attempt_number' => $run->attempt_number === null
                ? null
                : (int) $run->attempt_number,
            'exit_code' => $run->exit_code === null
                ? null
                : (int) $run->exit_code,
            'prompt_hash' => $this->rawString(
                $run->prompt_hash,
            ),
            'token_usage' => $run->token_usage === null
                ? null
                : (int) $run->token_usage,
            'configuration' => $this->configuration(
                $run->configuration_snapshot,
            ),
            'context_budget' => $this->budget(
                $run,
            ),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Reduce an immutable AgentRun configuration snapshot to stable configuration identities.
     *
     * @return array<string, mixed>|null
     */
    private function configuration(
        mixed $snapshot,
    ): ?array {
        if (! is_array($snapshot)) {
            return null;
        }

        $agent = $this->array(
            $snapshot['agent'] ?? null,
        );

        $skills = [];

        foreach (
            $snapshot['skills'] ?? [] as $skill
        ) {
            if (! is_array($skill)) {
                continue;
            }

            $skills[] = [
                'id' => is_int(
                    $skill['id'] ?? null,
                )
                    ? $skill['id']
                    : null,
                'slug' => $this->rawString(
                    $skill['slug'] ?? null,
                ),
                'version' => is_int(
                    $skill['version'] ?? null,
                )
                    ? $skill['version']
                    : null,
                'position' => is_int(
                    $skill['position'] ?? null,
                )
                    ? $skill['position']
                    : null,
            ];
        }

        usort(
            $skills,
            fn (
                array $left,
                array $right,
            ): int => [
                $left['position'] ?? PHP_INT_MAX,
                $left['id'] ?? PHP_INT_MAX,
            ] <=> [
                $right['position'] ?? PHP_INT_MAX,
                $right['id'] ?? PHP_INT_MAX,
            ],
        );

        return [
            'context_schema_version' => is_int(
                $snapshot['context_schema_version'] ?? null,
            )
                ? $snapshot['context_schema_version']
                : null,

            'context_hash' => $this->rawString(
                $snapshot['context_hash'] ?? null,
            ),

            'agent' => [
                'id' => is_int(
                    $agent['id'] ?? null,
                )
                    ? $agent['id']
                    : null,
                'role' => $this->rawString(
                    $agent['role'] ?? null,
                ),
                'harness' => $this->rawString(
                    $agent['harness'] ?? null,
                ),
                'model' => $this->rawString(
                    $agent['model'] ?? null,
                ),
                'reasoning_setting' => $this->rawString(
                    $agent['reasoning_setting'] ?? null,
                ),
                'configuration_version' => is_int(
                    $agent['configuration_version'] ?? null,
                )
                    ? $agent['configuration_version']
                    : null,
            ],

            'skills' => $skills,
        ];
    }

    /**
     * Select persisted Context Budget evidence needed to reproduce provider-facing decisions.
     *
     * @return array<string, mixed>|null
     */
    private function budget(
        AgentRun $run,
    ): ?array {
        $budget = $run->context_budget_snapshot;

        if (! is_array($budget)) {
            return null;
        }

        return [
            'schema_version' => $run->context_budget_schema_version,
            'policy_version' => is_int(
                $budget['policy_version'] ?? null,
            )
                ? $budget['policy_version']
                : null,
            'capacity_source' => $this->rawString(
                $budget['capacity_source'] ?? null,
            ),
            'capacity_source_version' => is_int(
                $budget['capacity_source_version'] ?? null,
            )
                ? $budget['capacity_source_version']
                : null,
            'resolved_capacity_tokens' => is_int(
                $budget['resolved_capacity_tokens'] ?? null,
            )
                ? $budget['resolved_capacity_tokens']
                : null,
            'target_percent' => is_int(
                $budget['target_percent'] ?? null,
            )
                ? $budget['target_percent']
                : null,
            'warning_percent' => is_int(
                $budget['warning_percent'] ?? null,
            )
                ? $budget['warning_percent']
                : null,
            'hard_ceiling_percent' => is_int(
                $budget['hard_ceiling_percent'] ?? null,
            )
                ? $budget['hard_ceiling_percent']
                : null,
            'original_estimated_tokens' => is_int(
                $budget['original_estimated_tokens'] ?? null,
            )
                ? $budget['original_estimated_tokens']
                : null,
            'final_estimated_tokens' => is_int(
                $budget['final_estimated_tokens'] ?? null,
            )
                ? $budget['final_estimated_tokens']
                : null,
            'required_estimated_tokens' => is_int(
                $budget['required_estimated_tokens'] ?? null,
            )
                ? $budget['required_estimated_tokens']
                : null,
            'source_contributions' => $this->array(
                $budget['source_contributions'] ?? null,
            ),
            'included_sources' => $this->strings(
                $budget['included_sources'] ?? [],
            ),
            'reduced_sources' => $this->strings(
                $budget['reduced_sources'] ?? [],
            ),
            'excluded_sources' => $this->strings(
                $budget['excluded_sources'] ?? [],
            ),
            'reductions' => is_array(
                $budget['reductions'] ?? null,
            )
                ? $budget['reductions']
                : [],
            'decision' => $this->rawString(
                $budget['decision'] ?? null,
            ),
            'original_context_hash' => $this->rawString(
                $budget['original_context_hash'] ?? null,
            ),
            'final_context_hash' => $this->rawString(
                $budget['final_context_hash'] ?? null,
            ),
            'original_prompt_hash' => $this->rawString(
                $budget['original_prompt_hash'] ?? null,
            ),
            'final_prompt_hash' => $this->rawString(
                $budget['final_prompt_hash'] ?? null,
            ),
        ];
    }

    /**
     * Query only allowlisted retry and no-progress audit events for the scoped Task.
     *
     * @return list<array<string, mixed>>
     */
    private function retryEvents(
        Project $project,
        ?Task $task,
    ): array {
        if ($task === null) {
            return [];
        }

        return AuditEvent::query()
            ->where(
                'project_id',
                $project->id,
            )
            ->where(
                'task_id',
                $task->id,
            )
            ->whereIn(
                'event_type',
                self::RetryEventTypes,
            )
            ->orderByDesc('id')
            ->limit(self::MaxAttempts)
            ->get()
            ->sortBy('id')
            ->map(
                fn (AuditEvent $event): array => $this->retryEvent(
                    $event,
                ),
            )
            ->values()
            ->all();
    }

    /**
     * Reduce one targeted retry audit event to structured retry/no-progress evidence.
     *
     * @return array<string, mixed>
     */
    private function retryEvent(
        AuditEvent $event,
    ): array {
        $payload = $this->array(
            $event->payload,
        );

        $noProgress = $this->noProgress(
            $payload['no_progress']
                ?? $payload,
        );

        return [
            'id' => (int) $event->id,
            'event_type' => (string) $event->event_type,
            'operation' => $this->rawString(
                $payload['operation'] ?? null,
            ),
            'attempt_number' => is_int(
                $payload['attempt_number'] ?? null,
            )
                ? $payload['attempt_number']
                : null,
            'retry_count' => is_int(
                $payload['retry_count'] ?? null,
            )
                ? $payload['retry_count']
                : null,
            'retry_limit' => is_int(
                $payload['retry_limit'] ?? null,
            )
                ? $payload['retry_limit']
                : null,
            'no_progress' => $noProgress,
            'base_sha' => $this->rawString(
                $payload['base_sha'] ?? null,
            ),
            'head_sha' => $this->rawString(
                $payload['head_sha'] ?? null,
            ),
            'commit_sha' => $this->rawString(
                $payload['commit_sha'] ?? null,
            ),
            'changed_files' => $this->strings(
                $payload['changed_files'] ?? [],
            ),
            'occurred_at' => $event->occurred_at?->toIso8601String(),
        ];
    }

    /**
     * Select the latest current retry, no-progress, and incident identity as protected failure evidence.
     *
     * @return array<string, mixed>|null
     */
    private function currentFailure(
        array $events,
        ?TaskAttempt $attempt,
        ?RecoveryIncident $incident,
    ): ?array {
        $latestEvent = $events === []
            ? null
            : $events[array_key_last($events)];

        $validation = $attempt === null
            ? []
            : $this->array(
                $attempt->validation_results,
            );

        $noProgress = $latestEvent['no_progress']
            ?? $this->noProgress(
                $validation['no_progress'] ?? null,
            );

        if (
            $latestEvent === null
            && $noProgress === null
            && $incident === null
        ) {
            return null;
        }

        return [
            'retry' => $latestEvent,
            'failure_fingerprint' => $noProgress['failure_fingerprint'] ?? null,
            'repository_fingerprint' => $noProgress['repository_fingerprint'] ?? null,
            'consecutive_identical_failures' => $noProgress['consecutive_identical_failures'] ?? null,
            'consecutive_repeat_count' => $noProgress['consecutive_repeat_count'] ?? null,
            'threshold' => $noProgress['threshold'] ?? null,
            'recovery_incident_id' => $incident?->id,
        ];
    }

    /**
     * Normalize persisted no-progress fingerprints and repeat counters without reconstructing reasoning.
     *
     * @return array<string, mixed>|null
     */
    private function noProgress(
        mixed $value,
    ): ?array {
        if (! is_array($value)) {
            return null;
        }

        $fingerprint = $this->rawString(
            $value['failure_fingerprint'] ?? null,
        );

        $repository = $this->rawString(
            $value['repository_fingerprint'] ?? null,
        );

        $repeat = $value['consecutive_repeat_count']
            ?? null;

        if (
            $fingerprint === null
            && $repository === null
            && ! is_int($repeat)
        ) {
            return null;
        }

        return [
            'detected' => is_bool(
                $value['detected'] ?? null,
            )
                ? $value['detected']
                : null,
            'failure_fingerprint' => $fingerprint,
            'repository_fingerprint' => $repository,
            'consecutive_identical_failures' => is_int(
                $value['consecutive_identical_failures'] ?? null,
            )
                ? $value['consecutive_identical_failures']
                : null,
            'consecutive_repeat_count' => is_int($repeat)
                ? $repeat
                : null,
            'threshold' => is_int(
                $value['threshold'] ?? null,
            )
                ? $value['threshold']
                : null,
        ];
    }

    /**
     * Query bounded historical RecoveryIncidents within the proven project or Task scope.
     *
     * @return list<RecoveryIncident>
     */
    private function incidents(
        Project $project,
        ?Task $task,
        ?RecoveryIncident $incident,
    ): array {
        if ($incident !== null) {
            return [];
        }

        return RecoveryIncident::query()
            ->where(
                'project_id',
                $project->id,
            )
            ->when(
                $task !== null,
                fn ($query) => $query->where(
                    'task_id',
                    $task->id,
                ),
            )
            ->orderByDesc('id')
            ->limit(self::MaxRecoveryIncidents)
            ->get()
            ->sortBy('id')
            ->values()
            ->all();
    }

    /**
     * Convert RecoveryIncident state to bounded diagnosis and validation evidence without claim secrets.
     *
     * @return array<string, mixed>
     */
    private function incident(
        RecoveryIncident $incident,
    ): array {
        return [
            'id' => (int) $incident->id,
            'task_id' => $incident->task_id === null
                ? null
                : (int) $incident->task_id,
            'source_agent_run_id' => $incident->source_agent_run_id === null
                ? null
                : (int) $incident->source_agent_run_id,
            'failure_type' => (string) $incident->failure_type,
            'status' => (string) $incident->getRawOriginal(
                'status',
            ),
            'root_cause_category' => $this->rawString(
                $incident->root_cause_category,
            ),
            'root_cause' => $this->text(
                $incident->root_cause,
            ),
            'recoverable' => $incident->recoverable,
            'attempt_count' => $incident->attempt_count === null
                ? null
                : (int) $incident->attempt_count,
            'fix_summary' => $this->text(
                $incident->fix_summary,
            ),
            'validation_evidence' => $this->recoveryValidation(
                $incident->validation_evidence,
            ),
            'resulting_task_transition' => $this->rawString(
                $incident->resulting_task_transition,
            ),
            'escalation_reason' => $this->text(
                $incident->escalation_reason,
            ),
            'base_sha' => $this->rawString(
                $incident->base_sha,
            ),
            'head_sha' => $this->rawString(
                $incident->head_sha,
            ),
            'commit_sha' => $this->rawString(
                $incident->commit_sha,
            ),
            'changed_files' => $this->strings(
                $incident->changed_files,
            ),
            'evidence_reference_hash' => is_array(
                $incident->evidence,
            )
                ? hash(
                    'sha256',
                    $this->json(
                        $this->canonical(
                            $incident->evidence,
                        ),
                    ),
                )
                : null,
            'detected_at' => $incident->detected_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
        ];
    }

    /**
     * Normalize RecoveryIncident validation evidence to deterministic pass/fail checks plus a stable evidence hash.
     *
     * @return array<string, mixed>|null
     */
    private function recoveryValidation(
        mixed $value,
    ): ?array {
        if (! is_array($value)) {
            return null;
        }

        $checks = $this->array(
            $value['checks'] ?? null,
        );

        ksort(
            $checks,
            SORT_STRING,
        );

        $failed = [];

        foreach ($checks as $name => $passed) {
            if (
                is_string($name)
                && $passed === false
            ) {
                $failed[] = $name;
            }
        }

        sort(
            $failed,
            SORT_STRING,
        );

        return [
            'passed' => is_bool(
                $value['passed'] ?? null,
            )
                ? $value['passed']
                : null,
            'checks' => $checks,
            'failed_checks' => $failed,
            'evidence_hash' => hash(
                'sha256',
                $this->json(
                    $this->canonical($value),
                ),
            ),
        ];
    }

    /**
     * Query bounded project-scoped knowledge candidates and retain only relevant references when scoped.
     *
     * @return list<KnowledgeImprovementCandidate>
     */
    private function knowledge(
        Project $project,
        ?Task $task,
        ?RecoveryIncident $incident,
    ): array {
        $candidates = KnowledgeImprovementCandidate::query()
            ->where(
                'project_id',
                $project->id,
            )
            ->orderByDesc('id')
            ->limit(self::CandidateScanLimit)
            ->get();

        if (
            $task !== null
            || $incident !== null
        ) {
            $candidates = $candidates->filter(
                fn (
                    KnowledgeImprovementCandidate $candidate,
                ): bool => $this->candidateRelevant(
                    $candidate,
                    $task,
                    $incident,
                ),
            );
        }

        return $candidates
            ->take(self::MaxKnowledgeCandidates)
            ->sortBy('id')
            ->values()
            ->all();
    }

    /** Determine whether persisted candidate references intersect the trusted Task or incident scope. */
    private function candidateRelevant(
        KnowledgeImprovementCandidate $candidate,
        ?Task $task,
        ?RecoveryIncident $incident,
    ): bool {
        foreach (
            $candidate->evidence ?? [] as $reference
        ) {
            if (! is_array($reference)) {
                continue;
            }

            if (
                $task !== null
                && ($reference['task_id'] ?? null) === $task->id
            ) {
                return true;
            }

            if (
                $incident !== null
                && (
                    $reference['recovery_incident_id'] ?? null
                ) === $incident->id
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert one knowledge candidate to proposal metadata and stable evidence references only.
     *
     * @return array<string, mixed>
     */
    private function candidate(
        KnowledgeImprovementCandidate $candidate,
        ?Task $task,
        ?RecoveryIncident $incident,
    ): array {
        $references = [];

        foreach (
            $candidate->evidence ?? [] as $reference
        ) {
            if (! is_array($reference)) {
                continue;
            }

            if (
                ($task === null && $incident === null)
                || (
                    $task !== null
                    && ($reference['task_id'] ?? null)
                        === $task->id
                )
                || (
                    $incident !== null
                    && (
                        $reference['recovery_incident_id']
                        ?? null
                    ) === $incident->id
                )
            ) {
                $references[] = [
                    'source_type' => $this->rawString(
                        $reference['source_type'] ?? null,
                    ),
                    'source_id' => is_int(
                        $reference['source_id'] ?? null,
                    )
                        ? $reference['source_id']
                        : null,
                    'task_id' => is_int(
                        $reference['task_id'] ?? null,
                    )
                        ? $reference['task_id']
                        : null,
                    'task_attempt_id' => is_int(
                        $reference['task_attempt_id'] ?? null,
                    )
                        ? $reference['task_attempt_id']
                        : null,
                    'review_id' => is_int(
                        $reference['review_id'] ?? null,
                    )
                        ? $reference['review_id']
                        : null,
                    'recovery_incident_id' => is_int(
                        $reference['recovery_incident_id'] ?? null,
                    )
                        ? $reference['recovery_incident_id']
                        : null,
                    'agent_run_id' => is_int(
                        $reference['agent_run_id'] ?? null,
                    )
                        ? $reference['agent_run_id']
                        : null,
                ];
            }
        }

        usort(
            $references,
            fn (
                array $left,
                array $right,
            ): int => [
                $left['source_type'] ?? '',
                $left['source_id'] ?? 0,
            ] <=> [
                $right['source_type'] ?? '',
                $right['source_id'] ?? 0,
            ],
        );

        $references = array_slice(
            $references,
            0,
            self::MaxKnowledgeCandidates,
        );

        return [
            'id' => (int) $candidate->id,
            'fingerprint' => (string) $candidate->fingerprint,
            'source_kind' => (string) $candidate->source_kind,
            'failure_code' => (string) $candidate->failure_code,
            'affected_role' => $this->rawString(
                $candidate->affected_role,
            ),
            'affected_area' => (string) $candidate->affected_area,
            'status' => (string) $candidate->getRawOriginal(
                'status',
            ),
            'target_type' => (string) $candidate->getRawOriginal(
                'target_type',
            ),
            'target_skill_id' => $candidate->target_skill_id === null
                ? null
                : (int) $candidate->target_skill_id,
            'occurrence_count' => (int) $candidate->occurrence_count,
            'evidence_hash' => (string) $candidate->evidence_hash,
            'evidence_references' => $references,
            'first_seen_at' => $candidate->first_seen_at?->toIso8601String(),
            'last_seen_at' => $candidate->last_seen_at?->toIso8601String(),
        ];
    }

    /**
     * Reuse the existing comparable Coder scorecard service for the scoped Task cohort.
     *
     * @return array<string, mixed>|null
     */
    private function scorecard(
        Project $project,
        ?Task $task,
    ): ?array {
        if ($task === null) {
            return null;
        }

        $workType = $this->rawString(
            $task->getRawOriginal('work_type'),
        );

        $complexity = $this->rawString(
            $task->getRawOriginal('complexity'),
        );

        if (
            $workType === null
            || $complexity === null
        ) {
            return null;
        }

        $result = $this->scorecards->calculate(
            $project,
            $project->tasks()
                ->orderBy('id')
                ->cursor(),
            TaskWorkType::from($workType),
            TaskComplexity::from($complexity),
        );

        return [
            'schema_version' => $result['schema_version'] ?? null,
            'score_version' => $result['score_version'] ?? null,
            'selected_cohort' => $result['selected_cohort'] ?? [],
            'sample' => $result['sample'] ?? [],
            'confidence' => $result['confidence'] ?? [],
            'methodology' => $result['methodology'] ?? [],
            'configuration_scores' => array_slice(
                is_array(
                    $result['configuration_scores'] ?? null,
                )
                    ? $result['configuration_scores']
                    : [],
                0,
                self::MaxScorecardConfigurations,
            ),
            'recommendation' => $result['recommendation'] ?? [],
        ];
    }

    /**
     * Build an explicit reproducibility manifest for every included, unavailable, or excluded evidence family.
     *
     * @return array<string, mixed>
     */
    private function manifest(
        Project $project,
        ?Task $task,
        ?RecoveryIncident $incident,
        Agent $orchestrator,
        array $attempts,
        ?Review $review,
        array $runs,
        array $retryEvents,
        array $incidents,
        array $knowledge,
        ?array $scorecard,
    ): array {
        $budgetRuns = array_values(
            array_filter(
                $runs,
                fn (AgentRun $run): bool => is_array(
                    $run->context_budget_snapshot,
                ),
            ),
        );

        return [
            'schema_version' => 1,

            'scope' => $this->scope(
                $project,
                $task,
                $incident,
            ),

            'sources' => [
                $this->source(
                    'current_agent_configuration',
                    'included',
                    1,
                    [(int) $orchestrator->id],
                ),

                $this->source(
                    'task_metadata',
                    $task === null
                        ? 'not_applicable'
                        : 'included',
                    $task === null ? 0 : 1,
                    $task === null
                        ? []
                        : [(int) $task->id],
                ),

                $this->source(
                    'agent_runs',
                    $runs === []
                        ? 'unavailable'
                        : 'included',
                    count($runs),
                    array_map(
                        fn (AgentRun $run): int => (int) $run->id,
                        $runs,
                    ),
                ),

                $this->source(
                    'coder_scorecard',
                    $scorecard === null
                        ? 'not_applicable'
                        : 'included',
                    $scorecard === null ? 0 : 1,
                    [],
                    [
                        'schema_version' => $scorecard['schema_version'] ?? null,
                        'score_version' => $scorecard['score_version'] ?? null,
                    ],
                ),

                $this->source(
                    'task_attempts',
                    $task === null
                        ? 'not_applicable'
                        : (
                            $attempts === []
                                ? 'unavailable'
                                : 'included'
                        ),
                    count($attempts),
                    array_map(
                        fn (
                            TaskAttempt $attempt,
                        ): int => (int) $attempt->id,
                        $attempts,
                    ),
                ),

                $this->source(
                    'current_review',
                    $task === null
                        ? 'not_applicable'
                        : (
                            $review === null
                                ? 'unavailable'
                                : 'included'
                        ),
                    $review === null ? 0 : 1,
                    $review === null
                        ? []
                        : [(int) $review->id],
                ),

                $this->source(
                    'context_budget_evidence',
                    $budgetRuns === []
                        ? 'unavailable'
                        : 'included',
                    count($budgetRuns),
                    array_map(
                        fn (
                            AgentRun $run,
                        ): int => (int) $run->id,
                        $budgetRuns,
                    ),
                    [
                        'schema_version' => ContextBudgetPolicy::SchemaVersion,
                        'policy_version' => ContextBudgetPolicy::PolicyVersion,
                    ],
                ),

                $this->source(
                    'retry_no_progress',
                    $task === null
                        ? 'not_applicable'
                        : (
                            $retryEvents === []
                                ? 'unavailable'
                                : 'included'
                        ),
                    count($retryEvents),
                    array_map(
                        fn (
                            array $event,
                        ): int => (int) $event['id'],
                        $retryEvents,
                    ),
                ),

                $this->source(
                    'recovery_incidents',
                    (
                        $incident !== null
                        || $incidents !== []
                    )
                        ? 'included'
                        : 'unavailable',
                    $incident !== null
                        ? 1
                        : count($incidents),
                    $incident !== null
                        ? [(int) $incident->id]
                        : array_map(
                            fn (
                                RecoveryIncident $item,
                            ): int => (int) $item->id,
                            $incidents,
                        ),
                ),

                $this->source(
                    'knowledge_improvements',
                    $knowledge === []
                        ? 'unavailable'
                        : 'included',
                    count($knowledge),
                    array_map(
                        fn (
                            KnowledgeImprovementCandidate $candidate,
                        ): int => (int) $candidate->id,
                        $knowledge,
                    ),
                ),

                $this->source(
                    'obsidian_project_knowledge',
                    'excluded_by_contract',
                ),

                $this->source(
                    'repository_contents',
                    'excluded_by_contract',
                ),

                $this->source(
                    'full_audit_history',
                    'excluded_by_contract',
                ),

                $this->source(
                    'provider_transcripts',
                    'excluded_by_contract',
                ),

                $this->source(
                    'operator_requester_history',
                    'excluded_by_contract',
                ),
            ],
        ];
    }

    /**
     * Build one deterministic retrieval-manifest source entry.
     *
     * @return array<string, mixed>
     */
    private function source(
        string $family,
        string $state,
        int $count = 0,
        array $ids = [],
        array $versions = [],
    ): array {
        sort(
            $ids,
            SORT_NUMERIC,
        );

        return [
            'family' => $family,
            'state' => $state,
            'count' => $count,
            'ids' => $ids,
            'versions' => $versions,
        ];
    }

    /**
     * Normalize set-like string evidence by path separator, uniqueness, sort order, and hard limit.
     *
     * @return list<string>
     */
    private function strings(
        mixed $value,
    ): array {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim(
                str_replace(
                    '\\',
                    '/',
                    $item,
                ),
            );

            if ($item !== '') {
                $strings[] = $item;
            }
        }

        $strings = array_values(
            array_unique($strings),
        );

        sort(
            $strings,
            SORT_STRING,
        );

        return array_slice(
            $strings,
            0,
            self::MaxChangedFiles,
        );
    }

    /** Return an array only when the persisted value is structured evidence. */
    private function array(
        mixed $value,
    ): array {
        return is_array($value)
            ? $value
            : [];
    }

    /** Normalize optional persisted strings without inventing values. */
    private function rawString(
        mixed $value,
    ): ?string {
        return is_string($value)
            && $value !== ''
                ? $value
                : null;
    }

    /** Bound persisted evidence text without introducing an LLM summarization step. */
    private function text(
        mixed $value,
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        return mb_strlen($value) <= self::MaxText
            ? $value
            : mb_substr(
                $value,
                0,
                self::MaxText,
            );
    }

    /** Encode deterministic evidence with the same JSON flags used by AgentContextAssembler. */
    private function json(
        mixed $value,
    ): string {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        );
    }

    /** Recursively sort associative keys while preserving the intentional order of list evidence. */
    private function canonical(
        mixed $value,
    ): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                $this->canonical(...),
                $value,
            );
        }

        ksort(
            $value,
            SORT_STRING,
        );

        foreach (
            $value as $key => $item
        ) {
            $value[$key] = $this->canonical(
                $item,
            );
        }

        return $value;
    }
}
