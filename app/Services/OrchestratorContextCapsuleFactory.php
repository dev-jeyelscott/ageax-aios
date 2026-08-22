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
use App\Models\ReviewFinding;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\TaskComplexity;
use App\TaskWorkType;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Resolve existing global-Agent and scorecard infrastructure.
     */
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
        $task = $this->validatedTaskScope($project, $task, $recoveryIncident);
        $orchestrator = $this->globalAgents->forRole(AgentRole::Orchestrator);
        $attempts = $this->attempts($task);
        $latestAttempt = $attempts === [] ? null : $attempts[array_key_last($attempts)];
        $review = $this->latestReview($task);
        $runs = $this->runs($project, $task, $recoveryIncident);
        $retryEvents = $this->retryEvents($project, $task);
        $incidents = $this->incidents($project, $task, $recoveryIncident);
        $knowledge = $this->knowledge($project, $task, $recoveryIncident);
        $scorecard = $this->scorecard($project, $task);

        $payload = [
            'schema_version' => self::SchemaVersion,
            'scope' => $this->scope($project, $task, $recoveryIncident),
            'task' => $this->task($task),
            'workflow_state' => $this->workflowState($project, $task, $recoveryIncident),
            'current_agent_configuration' => $this->currentAgent($orchestrator),
            'context_budget_policy' => [
                'schema_version' => ContextBudgetPolicy::SchemaVersion,
                'policy_version' => ContextBudgetPolicy::PolicyVersion,
            ],
            'previous_attempt' => $this->attempt($latestAttempt),
            'review' => $this->review($review),
            'review_findings' => $this->reviewFindings($review),
            'validation_evidence' => $this->validation($latestAttempt),
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
                'agent_runs' => array_map($this->run(...), $runs),
                'scorecard' => $scorecard,
                'attempts' => array_map(
                    fn (TaskAttempt $attempt): array => $this->attempt($attempt) ?? [],
                    $latestAttempt === null ? [] : array_slice($attempts, 0, -1),
                ),
                'retry_evidence' => $retryEvents,
                'recovery_incidents' => array_map($this->incident(...), $incidents),
                'knowledge_improvements' => array_map(
                    fn (KnowledgeImprovementCandidate $candidate): array => $this->candidate(
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
            'capsule_hash' => hash('sha256', $this->json($payload)),
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
            throw new LogicException('Orchestrator evidence Project scope must be persisted.');
        }

        $projectId = (int) $project->getKey();

        if ($task !== null && (! $task->exists || $this->intAttribute($task, 'project_id') !== $projectId)) {
            throw new LogicException(
                'Orchestrator evidence scope cannot cross the requested Project boundary.',
            );
        }

        if ($recoveryIncident === null) {
            return $task;
        }

        if (
            ! $recoveryIncident->exists
            || $this->intAttribute($recoveryIncident, 'project_id') !== $projectId
        ) {
            throw new LogicException(
                'Orchestrator evidence scope cannot cross the requested Project boundary.',
            );
        }

        $incidentTaskId = $this->intAttribute($recoveryIncident, 'task_id');
        $incidentTask = $incidentTaskId === null
            ? null
            : Task::query()
                ->where('project_id', $projectId)
                ->whereKey($incidentTaskId)
                ->first();

        if ($incidentTaskId !== null && $incidentTask === null) {
            throw new LogicException('Orchestrator RecoveryIncident Task scope is invalid.');
        }

        if ($task !== null && $incidentTask !== null && ! $task->is($incidentTask)) {
            throw new LogicException(
                'Orchestrator Task and RecoveryIncident scope must refer to the same Task.',
            );
        }

        return $task ?? $incidentTask;
    }

    /**
     * Build the stable typed scope identity included in the capsule and manifest.
     *
     * @return array<string, int|null>
     */
    private function scope(
        Project $project,
        ?Task $task,
        ?RecoveryIncident $incident,
    ): array {
        return [
            'project_id' => (int) $project->getKey(),
            'task_id' => $task === null ? null : (int) $task->getKey(),
            'recovery_incident_id' => $incident === null ? null : (int) $incident->getKey(),
        ];
    }

    /**
     * Select only allowlisted workflow-relevant Task metadata.
     *
     * @return array<string, mixed>|null
     */
    private function task(?Task $task): ?array
    {
        if ($task === null) {
            return null;
        }

        $task->loadMissing('phase');
        $dependencies = $task->dependencies()
            ->orderBy('tasks.id')
            ->get(['tasks.id', 'tasks.key']);

        $phasePosition = $task->phase === null
            ? null
            : $this->intAttribute($task->phase, 'position');

        return [
            'id' => (int) $task->getKey(),
            'key' => (string) $task->getAttribute('key'),
            'title' => (string) $task->getAttribute('title'),
            'objective' => (string) $task->getAttribute('objective'),
            'status' => (string) $task->getRawOriginal('status'),
            'phase_id' => $this->intAttribute($task, 'phase_id'),
            'phase_position' => $phasePosition,
            'position' => $this->intAttribute($task, 'position') ?? 0,
            'work_type' => $this->rawString($task->getRawOriginal('work_type')),
            'complexity' => $this->rawString($task->getRawOriginal('complexity')),
            'dependencies' => $dependencies
                ->map(
                    fn (Task $dependency): array => [
                        'id' => (int) $dependency->getKey(),
                        'key' => (string) $dependency->getAttribute('key'),
                    ],
                )
                ->values()
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
            'project_status' => (string) $project->getRawOriginal('status'),
            'task_status' => $task === null ? null : (string) $task->getRawOriginal('status'),
            'task_claimed_at' => $task === null ? null : $this->dateAttribute($task, 'claimed_at'),
            'task_completed_at' => $task === null ? null : $this->dateAttribute($task, 'completed_at'),
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
    private function currentAgent(Agent $agent): array
    {
        $defaultContext = $this->stringAttribute($agent, 'default_context');

        return [
            'id' => (int) $agent->getKey(),
            'name' => (string) $agent->getAttribute('name'),
            'role' => (string) $agent->getRawOriginal('role'),
            'harness' => (string) $agent->getRawOriginal('harness'),
            'model' => $this->rawString($agent->getRawOriginal('model')),
            'reasoning_setting' => $this->rawString($agent->getRawOriginal('reasoning_setting')),
            'default_context_hash' => $defaultContext === null
                ? null
                : hash('sha256', $defaultContext),
            'configuration_version' => $this->intAttribute($agent, 'configuration_version') ?? 0,
            'enabled' => (bool) $agent->getAttribute('enabled'),
        ];
    }

    /**
     * Read a bounded, deterministically ordered TaskAttempt history for the scoped Task.
     *
     * @return list<TaskAttempt>
     */
    private function attempts(?Task $task): array
    {
        if ($task === null) {
            return [];
        }

        $items = $task->attempts()
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

        /** @var list<TaskAttempt> $items */
        return $items;
    }

    /**
     * Convert one TaskAttempt to bounded durable retry and repository evidence.
     *
     * @return array<string, mixed>|null
     */
    private function attempt(?TaskAttempt $attempt): ?array
    {
        if ($attempt === null) {
            return null;
        }

        $validation = $this->arrayAttribute($attempt, 'validation_results');

        return [
            'id' => (int) $attempt->getKey(),
            'number' => $this->intAttribute($attempt, 'number') ?? 0,
            'base_sha' => $this->stringAttribute($attempt, 'base_sha'),
            'head_sha' => $this->stringAttribute($attempt, 'head_sha'),
            'commit_sha' => $this->stringAttribute($attempt, 'commit_sha'),
            'status' => (string) $attempt->getRawOriginal('status'),
            'changed_files' => $this->strings($attempt->getAttribute('changed_files')),
            'no_progress' => $this->noProgress($validation['no_progress'] ?? null),
            'started_at' => $this->dateAttribute($attempt, 'started_at'),
            'finished_at' => $this->dateAttribute($attempt, 'finished_at'),
        ];
    }

    /**
     * Extract deterministic validation checks and failed evidence from the latest attempt.
     *
     * @return array<string, mixed>|null
     */
    private function validation(?TaskAttempt $attempt): ?array
    {
        if ($attempt === null) {
            return null;
        }

        $validation = $this->arrayAttribute($attempt, 'validation_results');
        $checks = $this->stringKeyedArray($validation['checks'] ?? null);
        ksort($checks, SORT_STRING);

        $failed = [];

        foreach ($checks as $name => $passed) {
            if ($passed === false) {
                $failed[] = $name;
            }
        }

        sort($failed, SORT_STRING);

        /** @var array<string, array<string, mixed>> $evidence */
        $evidence = [];

        foreach ($this->stringKeyedArray($validation['evidence'] ?? null) as $name => $item) {
            if (! is_array($item) || ($item['passed'] ?? true) !== false) {
                continue;
            }

            $evidence[$name] = [
                'passed' => false,
                'name' => $this->rawString($item['name'] ?? null),
                'summary' => $this->text($item['summary'] ?? null),
                'reason' => $this->text($item['reason'] ?? null),
                'exit_code' => is_int($item['exit_code'] ?? null) ? $item['exit_code'] : null,
                'files' => $this->strings($item['files'] ?? []),
            ];
        }

        ksort($evidence, SORT_STRING);

        $taskContract = is_array($validation['task_contract'] ?? null)
            ? $validation['task_contract']
            : [];

        return [
            'attempt_id' => (int) $attempt->getKey(),
            'attempt_number' => $this->intAttribute($attempt, 'number') ?? 0,
            'passed' => is_bool($validation['passed'] ?? null) ? $validation['passed'] : null,
            'checks' => $checks,
            'failed_checks' => $failed,
            'failed_evidence' => $evidence,
            'task_contract_fingerprint' => $this->rawString($taskContract['fingerprint'] ?? null),
        ];
    }

    /**
     * Resolve the newest finalized Review for the scoped Task.
     */
    private function latestReview(?Task $task): ?Review
    {
        return $task?->reviews()
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Convert the latest persisted Review decision to bounded evidence.
     *
     * @return array<string, mixed>|null
     */
    private function review(?Review $review): ?array
    {
        if ($review === null) {
            return null;
        }

        return [
            'id' => (int) $review->getKey(),
            'task_attempt_id' => $this->intAttribute($review, 'task_attempt_id'),
            'status' => (string) $review->getRawOriginal('status'),
            'summary' => $this->text($review->getAttribute('summary')),
            'completed_at' => $this->dateAttribute($review, 'completed_at'),
        ];
    }

    /**
     * Select a bounded deterministic set of structured findings from the current Review.
     *
     * @return list<array<string, mixed>>
     */
    private function reviewFindings(?Review $review): array
    {
        if ($review === null) {
            return [];
        }

        $items = $review->findings()
            ->orderBy('id')
            ->limit(self::MaxFindings)
            ->get()
            ->map(
                fn (ReviewFinding $finding): array => [
                    'id' => (int) $finding->getKey(),
                    'severity' => $this->stringAttribute($finding, 'severity'),
                    'location' => $this->text($finding->getAttribute('location')),
                    'current_implementation' => $this->text(
                        $finding->getAttribute('current_implementation'),
                    ),
                    'expected_implementation' => $this->text(
                        $finding->getAttribute('expected_implementation'),
                    ),
                    'why_incorrect' => $this->text($finding->getAttribute('why_incorrect')),
                    'required_fix' => $this->text($finding->getAttribute('required_fix')),
                    'verification_requirement' => $this->text(
                        $finding->getAttribute('verification_requirement'),
                    ),
                    'implementation_fix_context' => $this->text(
                        $finding->getAttribute('implementation_fix_context'),
                    ),
                ],
            )
            ->values()
            ->all();

        /** @var list<array<string, mixed>> $items */
        return $items;
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
            ->where('project_id', $project->getKey());

        $sourceRunId = $incident === null
            ? null
            : $this->intAttribute($incident, 'source_agent_run_id');

        if ($task !== null) {
            $query->where(function ($scope) use ($task, $incident, $sourceRunId): void {
                $scope->where('task_id', $task->getKey());

                if ($incident !== null) {
                    $scope->orWhere('recovery_incident_id', $incident->getKey());

                    if ($sourceRunId !== null) {
                        $scope->orWhere('id', $sourceRunId);
                    }
                }
            });
        } elseif ($incident !== null) {
            $query->where(function ($scope) use ($incident, $sourceRunId): void {
                $scope->where('recovery_incident_id', $incident->getKey());

                if ($sourceRunId !== null) {
                    $scope->orWhere('id', $sourceRunId);
                }
            });
        }

        $items = $query
            ->orderByDesc('id')
            ->limit(self::MaxRuns)
            ->get()
            ->sortBy('id')
            ->values()
            ->all();

        /** @var list<AgentRun> $items */
        return $items;
    }

    /**
     * Convert an AgentRun to immutable configuration, outcome, hash, and budget identities only.
     *
     * @return array<string, mixed>
     */
    private function run(AgentRun $run): array
    {
        return [
            'id' => (int) $run->getKey(),
            'task_id' => $this->intAttribute($run, 'task_id'),
            'recovery_incident_id' => $this->intAttribute($run, 'recovery_incident_id'),
            'agent_id' => $this->intAttribute($run, 'agent_id'),
            'role' => (string) $run->getRawOriginal('role'),
            'harness' => $this->rawString($run->getRawOriginal('harness')),
            'status' => (string) $run->getRawOriginal('status'),
            'attempt_number' => $this->intAttribute($run, 'attempt_number'),
            'exit_code' => $this->intAttribute($run, 'exit_code'),
            'prompt_hash' => $this->stringAttribute($run, 'prompt_hash'),
            'token_usage' => $this->intAttribute($run, 'token_usage'),
            'configuration' => $this->configuration($run),
            'context_budget' => $this->budget($run),
            'started_at' => $this->dateAttribute($run, 'started_at'),
            'finished_at' => $this->dateAttribute($run, 'finished_at'),
        ];
    }

    /**
     * Reduce an immutable AgentRun configuration snapshot to stable configuration identities.
     *
     * @return array<string, mixed>|null
     */
    private function configuration(AgentRun $run): ?array
    {
        $snapshot = $this->arrayAttribute($run, 'configuration_snapshot');

        if ($snapshot === []) {
            return null;
        }

        $agent = is_array($snapshot['agent'] ?? null)
            ? $snapshot['agent']
            : [];

        /** @var list<array<string, mixed>> $skills */
        $skills = [];

        $snapshotSkills = is_array($snapshot['skills'] ?? null)
            ? $snapshot['skills']
            : [];

        foreach ($snapshotSkills as $skill) {
            if (! is_array($skill)) {
                continue;
            }

            $skills[] = [
                'id' => is_int($skill['id'] ?? null) ? $skill['id'] : null,
                'slug' => $this->rawString($skill['slug'] ?? null),
                'version' => is_int($skill['version'] ?? null) ? $skill['version'] : null,
                'position' => is_int($skill['position'] ?? null) ? $skill['position'] : null,
            ];
        }

        usort(
            $skills,
            fn (array $left, array $right): int => [
                $left['position'] ?? PHP_INT_MAX,
                $left['id'] ?? PHP_INT_MAX,
            ] <=> [
                $right['position'] ?? PHP_INT_MAX,
                $right['id'] ?? PHP_INT_MAX,
            ],
        );

        return [
            'context_schema_version' => is_int($snapshot['context_schema_version'] ?? null)
                ? $snapshot['context_schema_version']
                : null,
            'context_hash' => $this->rawString($snapshot['context_hash'] ?? null),
            'agent' => [
                'id' => is_int($agent['id'] ?? null) ? $agent['id'] : null,
                'role' => $this->rawString($agent['role'] ?? null),
                'harness' => $this->rawString($agent['harness'] ?? null),
                'model' => $this->rawString($agent['model'] ?? null),
                'reasoning_setting' => $this->rawString($agent['reasoning_setting'] ?? null),
                'configuration_version' => is_int($agent['configuration_version'] ?? null)
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
    private function budget(AgentRun $run): ?array
    {
        $budget = $this->arrayAttribute($run, 'context_budget_snapshot');

        if ($budget === []) {
            return null;
        }

        return [
            'schema_version' => $this->intAttribute($run, 'context_budget_schema_version'),
            'policy_version' => is_int($budget['policy_version'] ?? null)
                ? $budget['policy_version']
                : null,
            'capacity_source' => $this->rawString($budget['capacity_source'] ?? null),
            'capacity_source_version' => is_int($budget['capacity_source_version'] ?? null)
                ? $budget['capacity_source_version']
                : null,
            'resolved_capacity_tokens' => is_int($budget['resolved_capacity_tokens'] ?? null)
                ? $budget['resolved_capacity_tokens']
                : null,
            'target_percent' => is_int($budget['target_percent'] ?? null)
                ? $budget['target_percent']
                : null,
            'warning_percent' => is_int($budget['warning_percent'] ?? null)
                ? $budget['warning_percent']
                : null,
            'hard_ceiling_percent' => is_int($budget['hard_ceiling_percent'] ?? null)
                ? $budget['hard_ceiling_percent']
                : null,
            'original_estimated_tokens' => is_int($budget['original_estimated_tokens'] ?? null)
                ? $budget['original_estimated_tokens']
                : null,
            'final_estimated_tokens' => is_int($budget['final_estimated_tokens'] ?? null)
                ? $budget['final_estimated_tokens']
                : null,
            'required_estimated_tokens' => is_int($budget['required_estimated_tokens'] ?? null)
                ? $budget['required_estimated_tokens']
                : null,
            'source_contributions' => is_array($budget['source_contributions'] ?? null)
                ? $budget['source_contributions']
                : [],
            'included_sources' => $this->strings($budget['included_sources'] ?? []),
            'reduced_sources' => $this->strings($budget['reduced_sources'] ?? []),
            'excluded_sources' => $this->strings($budget['excluded_sources'] ?? []),
            'reductions' => is_array($budget['reductions'] ?? null) ? $budget['reductions'] : [],
            'decision' => $this->rawString($budget['decision'] ?? null),
            'original_context_hash' => $this->rawString($budget['original_context_hash'] ?? null),
            'final_context_hash' => $this->rawString($budget['final_context_hash'] ?? null),
            'original_prompt_hash' => $this->rawString($budget['original_prompt_hash'] ?? null),
            'final_prompt_hash' => $this->rawString($budget['final_prompt_hash'] ?? null),
        ];
    }

    /**
     * Query only allowlisted retry and no-progress audit events for the scoped Task.
     *
     * @return list<array<string, mixed>>
     */
    private function retryEvents(Project $project, ?Task $task): array
    {
        if ($task === null) {
            return [];
        }

        $items = AuditEvent::query()
            ->where('project_id', $project->getKey())
            ->where('task_id', $task->getKey())
            ->whereIn('event_type', self::RetryEventTypes)
            ->orderByDesc('id')
            ->limit(self::MaxAttempts)
            ->get()
            ->sortBy('id')
            ->map(fn (AuditEvent $event): array => $this->retryEvent($event))
            ->values()
            ->all();

        /** @var list<array<string, mixed>> $items */
        return $items;
    }

    /**
     * Reduce one targeted retry audit event to structured retry/no-progress evidence.
     *
     * @return array<string, mixed>
     */
    private function retryEvent(AuditEvent $event): array
    {
        $payload = $this->arrayAttribute($event, 'payload');
        $noProgress = $this->noProgress($payload['no_progress'] ?? $payload);

        return [
            'id' => (int) $event->getKey(),
            'event_type' => (string) $event->getAttribute('event_type'),
            'operation' => $this->rawString($payload['operation'] ?? null),
            'attempt_number' => is_int($payload['attempt_number'] ?? null)
                ? $payload['attempt_number']
                : null,
            'retry_count' => is_int($payload['retry_count'] ?? null)
                ? $payload['retry_count']
                : null,
            'retry_limit' => is_int($payload['retry_limit'] ?? null)
                ? $payload['retry_limit']
                : null,
            'no_progress' => $noProgress,
            'base_sha' => $this->rawString($payload['base_sha'] ?? null),
            'head_sha' => $this->rawString($payload['head_sha'] ?? null),
            'commit_sha' => $this->rawString($payload['commit_sha'] ?? null),
            'changed_files' => $this->strings($payload['changed_files'] ?? []),
            'occurred_at' => $this->dateAttribute($event, 'occurred_at'),
        ];
    }

    /**
     * Select the latest current retry, no-progress, and incident identity as protected failure evidence.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>|null
     */
    private function currentFailure(
        array $events,
        ?TaskAttempt $attempt,
        ?RecoveryIncident $incident,
    ): ?array {
        $latestEvent = $events === [] ? null : $events[array_key_last($events)];
        $validation = $attempt === null
            ? []
            : $this->arrayAttribute($attempt, 'validation_results');
        $noProgress = is_array($latestEvent['no_progress'] ?? null)
            ? $latestEvent['no_progress']
            : $this->noProgress($validation['no_progress'] ?? null);

        if ($latestEvent === null && $noProgress === null && $incident === null) {
            return null;
        }

        return [
            'retry' => $latestEvent,
            'failure_fingerprint' => $noProgress['failure_fingerprint'] ?? null,
            'repository_fingerprint' => $noProgress['repository_fingerprint'] ?? null,
            'consecutive_identical_failures' => $noProgress['consecutive_identical_failures'] ?? null,
            'consecutive_repeat_count' => $noProgress['consecutive_repeat_count'] ?? null,
            'threshold' => $noProgress['threshold'] ?? null,
            'recovery_incident_id' => $incident === null ? null : (int) $incident->getKey(),
        ];
    }

    /**
     * Normalize persisted no-progress fingerprints and repeat counters without reconstructing reasoning.
     *
     * @return array<string, mixed>|null
     */
    private function noProgress(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $fingerprint = $this->rawString($value['failure_fingerprint'] ?? null);
        $repository = $this->rawString($value['repository_fingerprint'] ?? null);
        $repeat = $value['consecutive_repeat_count'] ?? null;

        if ($fingerprint === null && $repository === null && ! is_int($repeat)) {
            return null;
        }

        return [
            'detected' => is_bool($value['detected'] ?? null) ? $value['detected'] : null,
            'failure_fingerprint' => $fingerprint,
            'repository_fingerprint' => $repository,
            'consecutive_identical_failures' => is_int(
                $value['consecutive_identical_failures'] ?? null,
            ) ? $value['consecutive_identical_failures'] : null,
            'consecutive_repeat_count' => is_int($repeat) ? $repeat : null,
            'threshold' => is_int($value['threshold'] ?? null) ? $value['threshold'] : null,
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

        $query = RecoveryIncident::query()
            ->where('project_id', $project->getKey());

        if ($task !== null) {
            $query->where('task_id', $task->getKey());
        }

        $items = $query
            ->orderByDesc('id')
            ->limit(self::MaxRecoveryIncidents)
            ->get()
            ->sortBy('id')
            ->values()
            ->all();

        /** @var list<RecoveryIncident> $items */
        return $items;
    }

    /**
     * Convert RecoveryIncident state to bounded diagnosis and validation evidence without claim secrets.
     *
     * @return array<string, mixed>
     */
    private function incident(RecoveryIncident $incident): array
    {
        $evidence = $this->arrayAttribute($incident, 'evidence');

        return [
            'id' => (int) $incident->getKey(),
            'task_id' => $this->intAttribute($incident, 'task_id'),
            'source_agent_run_id' => $this->intAttribute($incident, 'source_agent_run_id'),
            'failure_type' => (string) $incident->getAttribute('failure_type'),
            'status' => (string) $incident->getRawOriginal('status'),
            'root_cause_category' => $this->stringAttribute($incident, 'root_cause_category'),
            'root_cause' => $this->text($incident->getAttribute('root_cause')),
            'recoverable' => $this->boolAttribute($incident, 'recoverable'),
            'attempt_count' => $this->intAttribute($incident, 'attempt_count'),
            'fix_summary' => $this->text($incident->getAttribute('fix_summary')),
            'validation_evidence' => $this->recoveryValidation(
                $this->arrayAttribute($incident, 'validation_evidence'),
            ),
            'resulting_task_transition' => $this->stringAttribute(
                $incident,
                'resulting_task_transition',
            ),
            'escalation_reason' => $this->text($incident->getAttribute('escalation_reason')),
            'base_sha' => $this->stringAttribute($incident, 'base_sha'),
            'head_sha' => $this->stringAttribute($incident, 'head_sha'),
            'commit_sha' => $this->stringAttribute($incident, 'commit_sha'),
            'changed_files' => $this->strings($incident->getAttribute('changed_files')),
            'evidence_reference_hash' => $evidence === []
                ? null
                : hash('sha256', $this->json($this->canonical($evidence))),
            'detected_at' => $this->dateAttribute($incident, 'detected_at'),
            'resolved_at' => $this->dateAttribute($incident, 'resolved_at'),
        ];
    }

    /**
     * Normalize RecoveryIncident validation evidence to deterministic pass/fail checks plus a stable evidence hash.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>|null
     */
    private function recoveryValidation(array $value): ?array
    {
        if ($value === []) {
            return null;
        }

        $checks = $this->stringKeyedArray($value['checks'] ?? null);
        ksort($checks, SORT_STRING);
        $failed = [];

        foreach ($checks as $name => $passed) {
            if ($passed === false) {
                $failed[] = $name;
            }
        }

        sort($failed, SORT_STRING);

        return [
            'passed' => is_bool($value['passed'] ?? null) ? $value['passed'] : null,
            'checks' => $checks,
            'failed_checks' => $failed,
            'evidence_hash' => hash('sha256', $this->json($this->canonical($value))),
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
            ->where('project_id', $project->getKey())
            ->orderByDesc('id')
            ->limit(self::CandidateScanLimit)
            ->get();

        if ($task !== null || $incident !== null) {
            $candidates = $candidates->filter(
                fn (KnowledgeImprovementCandidate $candidate): bool => $this->candidateRelevant(
                    $candidate,
                    $task,
                    $incident,
                ),
            );
        }

        $items = $candidates
            ->take(self::MaxKnowledgeCandidates)
            ->sortBy('id')
            ->values()
            ->all();

        /** @var list<KnowledgeImprovementCandidate> $items */
        return $items;
    }

    /**
     * Determine whether persisted candidate references intersect the trusted Task or incident scope.
     */
    private function candidateRelevant(
        KnowledgeImprovementCandidate $candidate,
        ?Task $task,
        ?RecoveryIncident $incident,
    ): bool {
        $taskId = $task === null ? null : (int) $task->getKey();
        $incidentId = $incident === null ? null : (int) $incident->getKey();

        foreach ($this->arrayAttribute($candidate, 'evidence') as $reference) {
            if (! is_array($reference)) {
                continue;
            }

            if ($taskId !== null && ($reference['task_id'] ?? null) === $taskId) {
                return true;
            }

            if ($incidentId !== null && ($reference['recovery_incident_id'] ?? null) === $incidentId) {
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
        $taskId = $task === null ? null : (int) $task->getKey();
        $incidentId = $incident === null ? null : (int) $incident->getKey();

        /** @var list<array<string, mixed>> $references */
        $references = [];

        foreach ($this->arrayAttribute($candidate, 'evidence') as $reference) {
            if (! is_array($reference)) {
                continue;
            }

            $matchesScope = ($taskId === null && $incidentId === null)
                || ($taskId !== null && ($reference['task_id'] ?? null) === $taskId)
                || (
                    $incidentId !== null
                    && ($reference['recovery_incident_id'] ?? null) === $incidentId
                );

            if (! $matchesScope) {
                continue;
            }

            $references[] = [
                'source_type' => $this->rawString($reference['source_type'] ?? null),
                'source_id' => is_int($reference['source_id'] ?? null)
                    ? $reference['source_id']
                    : null,
                'task_id' => is_int($reference['task_id'] ?? null)
                    ? $reference['task_id']
                    : null,
                'task_attempt_id' => is_int($reference['task_attempt_id'] ?? null)
                    ? $reference['task_attempt_id']
                    : null,
                'review_id' => is_int($reference['review_id'] ?? null)
                    ? $reference['review_id']
                    : null,
                'recovery_incident_id' => is_int($reference['recovery_incident_id'] ?? null)
                    ? $reference['recovery_incident_id']
                    : null,
                'agent_run_id' => is_int($reference['agent_run_id'] ?? null)
                    ? $reference['agent_run_id']
                    : null,
            ];
        }

        usort(
            $references,
            fn (array $left, array $right): int => [
                $left['source_type'] ?? '',
                $left['source_id'] ?? 0,
            ] <=> [
                $right['source_type'] ?? '',
                $right['source_id'] ?? 0,
            ],
        );

        $references = array_slice($references, 0, self::MaxKnowledgeCandidates);

        return [
            'id' => (int) $candidate->getKey(),
            'fingerprint' => (string) $candidate->getAttribute('fingerprint'),
            'source_kind' => (string) $candidate->getAttribute('source_kind'),
            'failure_code' => (string) $candidate->getAttribute('failure_code'),
            'affected_role' => $this->stringAttribute($candidate, 'affected_role'),
            'affected_area' => (string) $candidate->getAttribute('affected_area'),
            'status' => (string) $candidate->getRawOriginal('status'),
            'target_type' => (string) $candidate->getRawOriginal('target_type'),
            'target_skill_id' => $this->intAttribute($candidate, 'target_skill_id'),
            'occurrence_count' => $this->intAttribute($candidate, 'occurrence_count') ?? 0,
            'evidence_hash' => (string) $candidate->getAttribute('evidence_hash'),
            'evidence_references' => $references,
            'first_seen_at' => $this->dateAttribute($candidate, 'first_seen_at'),
            'last_seen_at' => $this->dateAttribute($candidate, 'last_seen_at'),
        ];
    }

    /**
     * Reuse the existing comparable Coder scorecard service for the scoped Task cohort.
     *
     * @return array<string, mixed>|null
     */
    private function scorecard(Project $project, ?Task $task): ?array
    {
        if ($task === null) {
            return null;
        }

        $workType = $task->getAttribute('work_type');
        $complexity = $task->getAttribute('complexity');

        if (! $workType instanceof TaskWorkType || ! $complexity instanceof TaskComplexity) {
            return null;
        }

        $result = $this->scorecards->calculate(
            $project,
            $project->tasks()->orderBy('id')->cursor(),
            $workType,
            $complexity,
        );

        $configurationScores = is_array($result['configuration_scores'] ?? null)
            ? $result['configuration_scores']
            : [];

        return [
            'schema_version' => $result['schema_version'] ?? null,
            'score_version' => $result['score_version'] ?? null,
            'selected_cohort' => is_array($result['selected_cohort'] ?? null)
                ? $result['selected_cohort']
                : [],
            'sample' => is_array($result['sample'] ?? null) ? $result['sample'] : [],
            'confidence' => is_array($result['confidence'] ?? null) ? $result['confidence'] : [],
            'methodology' => is_array($result['methodology'] ?? null) ? $result['methodology'] : [],
            'configuration_scores' => array_slice(
                $configurationScores,
                0,
                self::MaxScorecardConfigurations,
            ),
            'recommendation' => is_array($result['recommendation'] ?? null)
                ? $result['recommendation']
                : [],
        ];
    }

    /**
     * Build an explicit reproducibility manifest for every included, unavailable, or excluded evidence family.
     *
     * @param  list<TaskAttempt>  $attempts
     * @param  list<AgentRun>  $runs
     * @param  list<array<string, mixed>>  $retryEvents
     * @param  list<RecoveryIncident>  $incidents
     * @param  list<KnowledgeImprovementCandidate>  $knowledge
     * @param  array<string, mixed>|null  $scorecard
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
                fn (AgentRun $run): bool => $this->arrayAttribute(
                    $run,
                    'context_budget_snapshot',
                ) !== [],
            ),
        );

        return [
            'schema_version' => 1,
            'scope' => $this->scope($project, $task, $incident),
            'sources' => [
                $this->source(
                    'current_agent_configuration',
                    'included',
                    1,
                    [(int) $orchestrator->getKey()],
                ),
                $this->source(
                    'task_metadata',
                    $task === null ? 'not_applicable' : 'included',
                    $task === null ? 0 : 1,
                    $task === null ? [] : [(int) $task->getKey()],
                ),
                $this->source(
                    'agent_runs',
                    $runs === [] ? 'unavailable' : 'included',
                    count($runs),
                    array_map(fn (AgentRun $run): int => (int) $run->getKey(), $runs),
                ),
                $this->source(
                    'coder_scorecard',
                    $scorecard === null ? 'not_applicable' : 'included',
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
                        : ($attempts === [] ? 'unavailable' : 'included'),
                    count($attempts),
                    array_map(
                        fn (TaskAttempt $attempt): int => (int) $attempt->getKey(),
                        $attempts,
                    ),
                ),
                $this->source(
                    'current_review',
                    $task === null
                        ? 'not_applicable'
                        : ($review === null ? 'unavailable' : 'included'),
                    $review === null ? 0 : 1,
                    $review === null ? [] : [(int) $review->getKey()],
                ),
                $this->source(
                    'context_budget_evidence',
                    $budgetRuns === [] ? 'unavailable' : 'included',
                    count($budgetRuns),
                    array_map(fn (AgentRun $run): int => (int) $run->getKey(), $budgetRuns),
                    [
                        'schema_version' => ContextBudgetPolicy::SchemaVersion,
                        'policy_version' => ContextBudgetPolicy::PolicyVersion,
                    ],
                ),
                $this->source(
                    'retry_no_progress',
                    $task === null
                        ? 'not_applicable'
                        : ($retryEvents === [] ? 'unavailable' : 'included'),
                    count($retryEvents),
                    array_map(fn (array $event): int => (int) $event['id'], $retryEvents),
                ),
                $this->source(
                    'recovery_incidents',
                    ($incident !== null || $incidents !== []) ? 'included' : 'unavailable',
                    $incident !== null ? 1 : count($incidents),
                    $incident !== null
                        ? [(int) $incident->getKey()]
                        : array_map(
                            fn (RecoveryIncident $item): int => (int) $item->getKey(),
                            $incidents,
                        ),
                ),
                $this->source(
                    'knowledge_improvements',
                    $knowledge === [] ? 'unavailable' : 'included',
                    count($knowledge),
                    array_map(
                        fn (KnowledgeImprovementCandidate $candidate): int => (int) $candidate->getKey(),
                        $knowledge,
                    ),
                ),
                $this->source('obsidian_project_knowledge', 'excluded_by_contract'),
                $this->source('repository_contents', 'excluded_by_contract'),
                $this->source('full_audit_history', 'excluded_by_contract'),
                $this->source('provider_transcripts', 'excluded_by_contract'),
                $this->source('operator_requester_history', 'excluded_by_contract'),
            ],
        ];
    }

    /**
     * Build one deterministic retrieval-manifest source entry.
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $versions
     * @return array<string, mixed>
     */
    private function source(
        string $family,
        string $state,
        int $count = 0,
        array $ids = [],
        array $versions = [],
    ): array {
        sort($ids, SORT_NUMERIC);

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
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim(str_replace('\\', '/', $item));

            if ($item !== '') {
                $strings[] = $item;
            }
        }

        $strings = array_values(array_unique($strings));
        sort($strings, SORT_STRING);

        return array_slice($strings, 0, self::MaxChangedFiles);
    }

    /**
     * Read one cast array attribute through Eloquent's runtime cast boundary.
     *
     * @return array<array-key, mixed>
     */
    private function arrayAttribute(Model $model, string $attribute): array
    {
        $value = $model->getAttribute($attribute);

        return is_array($value) ? $value : [];
    }

    /**
     * Narrow a mixed value to an associative string-keyed array for deterministic object-like evidence.
     *
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * Read an integer Eloquent attribute without relying on a magic-property PHPDoc inference.
     */
    private function intAttribute(Model $model, string $attribute): ?int
    {
        $value = $model->getAttribute($attribute);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value)
            ? (int) $value
            : null;
    }

    /**
     * Read a boolean Eloquent attribute without relying on a magic-property PHPDoc inference.
     */
    private function boolAttribute(Model $model, string $attribute): ?bool
    {
        $value = $model->getAttribute($attribute);

        return is_bool($value) ? $value : null;
    }

    /**
     * Read a string Eloquent attribute without relying on a magic-property PHPDoc inference.
     */
    private function stringAttribute(Model $model, string $attribute): ?string
    {
        return $this->rawString($model->getAttribute($attribute));
    }

    /**
     * Read a cast date Eloquent attribute and serialize it without trusting magic-property types.
     */
    private function dateAttribute(Model $model, string $attribute): ?string
    {
        $value = $model->getAttribute($attribute);

        return $value instanceof DateTimeInterface
            ? $value->format(DATE_ATOM)
            : null;
    }

    /**
     * Normalize optional persisted strings without inventing values.
     */
    private function rawString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Bound persisted evidence text without introducing an LLM summarization step.
     */
    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return mb_strlen($value) <= self::MaxText
            ? $value
            : mb_substr($value, 0, self::MaxText);
    }

    /**
     * Encode deterministic evidence with the same JSON flags used by AgentContextAssembler.
     */
    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Recursively sort associative keys while preserving the intentional order of list evidence.
     */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonical(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }

        return $value;
    }
}
