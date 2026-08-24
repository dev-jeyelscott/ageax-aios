<?php

namespace App\Services;

use App\AgentRole;
use App\AgentRunStatus;
use App\Exceptions\DatabaseProtectionFailed;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Task;
use App\RecoveryIncidentStatus;
use App\RuntimeRecoverabilityClassification;
use App\RuntimeRecoveryIncidentFamily;
use App\TaskStatus;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * AIOS-owned workflow and runtime recovery engine.
 *
 * Recovery Engineer executions are disposable. Laravel/AIOS retains exclusive
 * authority over claiming, retry limits, durable state, validation, Git,
 * commits, recovery transitions, auditing, and escalation.
 */
class WorkflowRecoveryEngine
{
    private const array KnownDeterministicBlocks = [
        'task.blocked_dirty_repository' => 'unsafe_git_state',
        'task.blocked_agent_misconfigured' => 'configuration_environment',
        'task.blocked_unsafe_path' => 'configuration_environment',
    ];

    private const int RecoveryHistoryLimit = 5;

    private const int RecoveryContextArrayLimit = 25;

    private const int RecoveryContextDepthLimit = 4;

    private const int RecoveryContextStringLimit = 2048;

    private const int RecoverySummaryLimit = 4000;

    private const int RecoveryPathLimit = 512;

    public function __construct(
        private AuditLogger $audit,
        private TaskWorkflow $workflow,
        private RecoveryEngineerRunner $engineer,
        private RecoveryWorktreeManager $worktrees,
        private AgentRunRecorder $runs,
        private RecoveryRepositoryLifecycle $lifecycle,
        private ProjectGitState $git,
        private DirtyRepositoryAttributor $attributor,
        private GlobalAgentResolver $globalAgents,
        private AgentHarnessResolver $harnesses,
        private DatabaseProtectionGuard $databaseProtection,
        private RuntimeRecoverabilityPolicy $runtimePolicy,
        private NoProgressRetryGuard $noProgress,
        private StaleWorkerRecovery $staleWorkerRecovery,
        private AgentContextAssembler $contextAssembler,
        private SensitiveDataSanitizer $sanitizer,
    ) {}

    /**
     * Reclaim workflow/runtime recovery claims abandoned by a dead Recovery Engineer execution.
     */
    public function reclaimStaleClaims(Project $project): void
    {
        $staleAfterSeconds = max(1, (int) config('aios.recovery_claim_stale_after_seconds'));

        RecoveryIncident::query()
            ->whereBelongsTo($project)
            ->whereIn('status', [
                RecoveryIncidentStatus::Diagnosing,
                RecoveryIncidentStatus::Repairing,
                RecoveryIncidentStatus::Validating,
            ])
            ->where('claimed_at', '<=', now()->subSeconds($staleAfterSeconds))
            ->get()
            ->each(fn (RecoveryIncident $incident): RecoveryIncident => $this->reclaimIncidentClaim($incident));
    }

    /**
     * Reclaim stale claims for runtime incidents that have no managed-project scope.
     */
    public function reclaimStaleUnscopedRuntimeClaims(): void
    {
        $staleAfterSeconds = max(1, (int) config('aios.recovery_claim_stale_after_seconds'));

        RecoveryIncident::query()
            ->whereNull('project_id')
            ->whereIn('failure_type', $this->runtimeFailureTypes())
            ->whereIn('status', [
                RecoveryIncidentStatus::Diagnosing,
                RecoveryIncidentStatus::Repairing,
                RecoveryIncidentStatus::Validating,
            ])
            ->where('claimed_at', '<=', now()->subSeconds($staleAfterSeconds))
            ->get()
            ->each(fn (RecoveryIncident $incident): RecoveryIncident => $this->reclaimIncidentClaim($incident));
    }

    /**
     * Process every currently detected project recovery incident serially.
     */
    public function processOpenIncidents(Project $project): void
    {
        RecoveryIncident::query()
            ->whereBelongsTo($project)
            ->where('status', RecoveryIncidentStatus::Detected)
            ->orderBy('detected_at')
            ->get()
            ->each(fn (RecoveryIncident $incident): RecoveryIncident => $this->process($incident));
    }

    /**
     * Process global runtime incidents that cannot be scoped to a managed project.
     */
    public function processUnscopedRuntimeIncidents(): void
    {
        RecoveryIncident::query()
            ->whereNull('project_id')
            ->whereIn('failure_type', $this->runtimeFailureTypes())
            ->where('status', RecoveryIncidentStatus::Detected)
            ->orderBy('detected_at')
            ->get()
            ->each(fn (RecoveryIncident $incident): RecoveryIncident => $this->process($incident));
    }

    /**
     * Route one incident into the workflow or runtime recovery lifecycle.
     */
    public function process(RecoveryIncident $incident): RecoveryIncident
    {
        $runtimeFamily = RuntimeRecoveryIncidentFamily::tryFrom((string) $incident->failure_type);

        if ($runtimeFamily !== null) {
            return $this->processRuntimeIncident($incident);
        }

        if (! $this->claim($incident)) {
            return $incident->fresh();
        }

        $incident = $incident->fresh();
        $task = $incident->task_id === null
            ? null
            : Task::query()->find($incident->task_id);

        if ($task !== null && ! $this->stillEligible($task)) {
            return $this->resolveAlreadyHandled($incident, $task);
        }

        $classification = $this->classifyDeterministically($task)
            ?? $this->diagnoseSafely($incident, $task);

        $maxAttempts = max(1, (int) config('aios.recovery_max_attempts'));

        DB::transaction(function () use ($incident, $classification): void {
            RecoveryIncident::query()
                ->whereKey($incident->id)
                ->update([
                    'root_cause' => $classification['summary'],
                    'root_cause_category' => $classification['category'],
                    'recoverable' => $classification['recoverable'],
                    'attempt_count' => $incident->attempt_count + 1,
                ]);
        }, attempts: 3);

        $incident = $incident->fresh();

        if (
            $classification['category'] === 'transient_harness_failure'
            && $incident->attempt_count < $maxAttempts
        ) {
            $incident->update([
                'status' => RecoveryIncidentStatus::Detected,
            ]);

            $this->audit->record('recovery.retry_scheduled', [
                'recovery_incident_id' => $incident->id,
                'attempt_count' => $incident->attempt_count,
                'retry_limit' => $maxAttempts,
            ], $incident->project, $task);

            return $incident->fresh();
        }

        if (! $classification['recoverable'] || $incident->attempt_count >= $maxAttempts) {
            return $this->escalate(
                $incident,
                $classification,
                $task,
                $incident->attempt_count >= $maxAttempts
                    ? 'Bounded recovery attempt limit reached.'
                    : null,
                is_array($classification['validation_evidence'] ?? null)
                    ? $classification['validation_evidence']
                    : null,
            );
        }

        if (
            $task !== null
            && isset($classification['origin_task_id'])
            && $classification['origin_task_id'] !== $task->id
        ) {
            $originTask = Task::query()
                ->whereKey((int) $classification['origin_task_id'])
                ->first();

            if ($originTask !== null) {
                return $this->recoverViaOriginatingTask(
                    $incident,
                    $task,
                    $originTask,
                    $classification['summary'],
                );
            }
        }

        if ($classification['fix_applied'] && $classification['changed_files'] !== []) {
            return $this->applyRepair($incident, $classification, $task);
        }

        return $this->recover($incident, $task, $classification['summary']);
    }

    /**
     * Classify one runtime incident and execute only candidate AI repairs.
     */
    private function processRuntimeIncident(RecoveryIncident $incident): RecoveryIncident
    {
        $incident = $incident->fresh();

        if (
            RecoveryIncidentStatus::from((string) $incident->getRawOriginal('status'))
            !== RecoveryIncidentStatus::Detected
        ) {
            return $incident;
        }

        if (! $this->claim($incident)) {
            return $incident->fresh();
        }

        $incident = $incident->fresh();
        $task = $incident->task_id === null
            ? null
            : Task::query()->find($incident->task_id);

        $alreadyCandidate = $incident->root_cause_category
            === RuntimeRecoverabilityClassification::CandidateAiRepair->value;

        $classification = $alreadyCandidate
            ? $this->persistedCandidateRuntimeClassification($incident)
            : $this->runtimePolicy->classify($incident);

        if (! $alreadyCandidate) {
            $incident->update([
                'root_cause' => $classification['summary'],
                'root_cause_category' => $classification['category'],
                'recoverable' => $classification['recoverable'],
            ]);

            $incident = $incident->fresh();

            $this->audit->record('recovery.runtime_classified', [
                'recovery_incident_id' => $incident->id,
                'failure_type' => $incident->failure_type,
                'fingerprint' => $incident->fingerprint,
                'classification' => $classification['category'],
                'deterministic_repair' => $classification['deterministic_repair'],
            ], $incident->project, $task);
        }

        return match (RuntimeRecoverabilityClassification::from($classification['category'])) {
            RuntimeRecoverabilityClassification::KnownDeterministicRepair => $this->applyKnownRuntimeRepair(
                $incident,
                $classification,
                $task,
            ),
            RuntimeRecoverabilityClassification::CandidateAiRepair => $this->attemptRuntimeAiRepair(
                $incident,
                $classification,
                $task,
            ),
            RuntimeRecoverabilityClassification::OperatorOnly => $this->escalateRuntime(
                $incident,
                $classification,
                $task,
            ),
            RuntimeRecoverabilityClassification::NonActionable => $this->closeNonActionableRuntimeIncident(
                $incident,
                $task,
            ),
        };
    }

    /**
     * Rebuild the stable P7-003 candidate classification without re-running policy on each retry.
     *
     * @return array<string, mixed>
     */
    private function persistedCandidateRuntimeClassification(RecoveryIncident $incident): array
    {
        return [
            'category' => RuntimeRecoverabilityClassification::CandidateAiRepair->value,
            'summary' => filled($incident->root_cause)
                ? (string) $incident->root_cause
                : 'The runtime incident is bounded and project-scoped, but no deterministic repair is proven safe.',
            'recoverable' => true,
            'fix_applied' => false,
            'changed_files' => [],
            'fix_summary' => null,
            'escalation_reason' => null,
            'deterministic_repair' => null,
        ];
    }

    /**
     * Execute one project-scoped candidate runtime repair through the existing isolated Recovery Engineer lifecycle.
     *
     * @param  array<string, mixed>  $classification
     */
    private function attemptRuntimeAiRepair(
        RecoveryIncident $incident,
        array $classification,
        ?Task $task,
    ): RecoveryIncident {
        $maxAttempts = max(1, (int) config('aios.recovery_max_attempts'));

        if ($incident->attempt_count >= $maxAttempts) {
            return $this->escalateRuntime(
                $incident,
                $classification,
                $task,
                'Bounded recovery attempt limit reached.',
            );
        }

        if ($incident->project === null) {
            return $this->escalateRuntime(
                $incident,
                $classification,
                $task,
                'Runtime AI repair requires a provable managed-project scope.',
            );
        }

        try {
            $agent = $this->globalAgents->forRole(AgentRole::RecoveryEngineer);
            $this->harnesses->resolve($agent);
        } catch (LogicException $exception) {
            $reason = $this->safeRecoveryText($exception->getMessage())
                ?? 'The global Recovery Engineer Agent is unavailable.';

            $this->audit->record('recovery.blocked_agent_misconfigured', [
                'recovery_incident_id' => $incident->id,
                'reason' => $reason,
            ], $incident->project, $task);

            return $this->escalateRuntime(
                $incident,
                $classification,
                $task,
                $reason,
            );
        }

        $repositoryPath = (string) config('aios.recovery_repository_path');
        $preflight = $this->lifecycle->preflight($repositoryPath);

        if (! $preflight['clean'] || $preflight['head_sha'] === null) {
            return $this->escalateRuntime(
                $incident,
                $classification,
                $task,
                'AIOS recovery repository preflight failed before runtime AI repair.',
            );
        }

        try {
            $worktreePath = $this->worktrees->create(
                $repositoryPath,
                $preflight['head_sha'],
            );
        } catch (Throwable $exception) {
            $this->audit->record('recovery.blocked_worktree_isolation_failed', [
                'recovery_incident_id' => $incident->id,
                'reason' => $this->safeRecoveryText($exception->getMessage()),
            ], $incident->project, $task);

            return $this->escalateRuntime(
                $incident,
                $classification,
                $task,
                'Recovery worktree isolation failed before runtime AI repair.',
            );
        }

        /** @var array<string, mixed>|null $proposal */
        $proposal = null;

        try {
            try {
                $assembled = $this->contextAssembler->assemble(
                    $agent,
                    AgentRole::RecoveryEngineer,
                    $this->buildRuntimeRecoveryContext(
                        $incident,
                        $task,
                        $preflight['head_sha'],
                    ),
                );

                $prompt = $this->buildRecoveryPrompt($assembled);

                $run = $this->runs->start(
                    $incident->project,
                    AgentRole::RecoveryEngineer,
                    $prompt,
                    $task,
                    agent: $agent,
                    context: $assembled,
                );

                $run->update([
                    'recovery_incident_id' => $incident->id,
                ]);
            } catch (Throwable $exception) {
                $this->audit->record('recovery.runtime_ai_repair_preparation_failed', [
                    'recovery_incident_id' => $incident->id,
                    'reason' => $this->safeRecoveryText($exception->getMessage()),
                ], $incident->project, $task);

                return $this->escalateRuntime(
                    $incident,
                    $classification,
                    $task,
                    'Recovery Engineer execution context could not be prepared safely.',
                );
            }

            try {
                $this->databaseProtection->guard();
            } catch (DatabaseProtectionFailed $exception) {
                $this->runs->complete(
                    $run,
                    $this->failedExecution(
                        'Database protection blocked Recovery Engineer execution.',
                    ),
                );

                $this->audit->record('recovery.blocked_database_protection_failed', [
                    'recovery_incident_id' => $incident->id,
                    'reason' => $this->safeRecoveryText($exception->getMessage()),
                ], $incident->project, $task);

                return $this->escalateRuntime(
                    $incident,
                    $classification,
                    $task,
                    'Database protection guard failed before runtime AI repair.',
                );
            }

            $incident->update([
                'status' => RecoveryIncidentStatus::Repairing,
                'attempt_count' => $incident->attempt_count + 1,
            ]);

            $incident = $incident->fresh();

            $this->audit->record('recovery.runtime_ai_repair_started', [
                'recovery_incident_id' => $incident->id,
                'attempt_count' => $incident->attempt_count,
                'base_sha' => $preflight['head_sha'],
                'agent_run_id' => $run->id,
            ], $incident->project, $task);

            try {
                $result = $this->engineer->run(
                    $agent,
                    $prompt,
                    $worktreePath,
                );
            } catch (Throwable $exception) {
                $reason = 'Recovery Engineer execution threw ['.$exception::class.'].';

                $this->runs->complete(
                    $run,
                    $this->failedExecution($reason),
                );

                return $this->recordRuntimeAttemptFailure(
                    $incident,
                    $classification,
                    $task,
                    $reason,
                );
            }

            $this->runs->complete($run, $result['execution']);

            if (
                $result['execution']['exit_code'] !== 0
                || $result['decision'] === null
            ) {
                return $this->recordRuntimeAttemptFailure(
                    $incident,
                    $classification,
                    $task,
                    'Recovery Engineer execution failed or returned no structured decision.',
                );
            }

            $proposal = $this->inspectRecoveryProposal(
                $result['decision'],
                $run->fresh(),
                $worktreePath,
                $repositoryPath,
                $preflight['head_sha'],
            );

            if (! $proposal['passed']) {
                $incident->update([
                    'status' => RecoveryIncidentStatus::Validating,
                    'base_sha' => $preflight['head_sha'],
                    'changed_files' => $proposal['changed_files'],
                    'validation_evidence' => $proposal['validation'],
                ]);

                return $this->recordRuntimeAttemptFailure(
                    $incident->fresh(),
                    $classification,
                    $task,
                    $proposal['reason']
                        ?? 'The proposed runtime repair failed AIOS validation.',
                );
            }

            $incident->update([
                'root_cause' => $proposal['decision']['root_cause_summary'],
                'base_sha' => $preflight['head_sha'],
                'changed_files' => $proposal['changed_files'],
                'validation_evidence' => $proposal['validation'],
            ]);

            $incident = $incident->fresh();

            if (! $proposal['decision']['recoverable']) {
                return $this->escalateRuntime(
                    $incident,
                    $classification,
                    $task,
                    $proposal['decision']['escalation_reason']
                        ?? 'Recovery Engineer determined that automatic repair is unsafe.',
                );
            }

            if (! $proposal['decision']['fix_applied']) {
                return $this->recordRuntimeAttemptFailure(
                    $incident,
                    $classification,
                    $task,
                    'Recovery Engineer completed without producing a bounded AIOS code fix.',
                );
            }
        } finally {
            $this->worktrees->destroy($repositoryPath, $worktreePath);
        }

        return $this->applyRuntimeRepair(
            $incident->fresh(),
            $classification,
            $proposal,
            $task,
        );
    }

    /**
     * Apply an allowlisted deterministic runtime repair.
     *
     * @param  array<string, mixed>  $classification
     */
    private function applyKnownRuntimeRepair(
        RecoveryIncident $incident,
        array $classification,
        ?Task $task,
    ): RecoveryIncident {
        $maxAttempts = max(1, (int) config('aios.recovery_max_attempts'));

        if ($incident->attempt_count >= $maxAttempts) {
            return $this->escalateRuntime(
                $incident,
                $classification,
                $task,
                'Bounded recovery attempt limit reached.',
            );
        }

        $incident->update([
            'status' => RecoveryIncidentStatus::Repairing,
            'attempt_count' => $incident->attempt_count + 1,
        ]);

        $incident = $incident->fresh();

        if (
            $classification['deterministic_repair'] !== 'stale_worker_recovery'
            || $incident->project === null
        ) {
            return $this->escalateRuntime(
                $incident,
                $classification,
                $task,
                'The classified deterministic repair has no allowlisted executable AIOS handler.',
            );
        }

        try {
            $recovered = $this->staleWorkerRecovery->recover(
                $incident->project,
                (int) config('aios.stale_worker_after_seconds'),
            );
        } catch (Throwable $exception) {
            return $this->recordRuntimeAttemptFailure(
                $incident,
                $classification,
                $task,
                'The deterministic stale-worker recovery handler threw ['.$exception::class.'].',
            );
        }

        if ($recovered === 0 && ! $this->staleWorkerIncidentStillActionable($incident)) {
            $incident->update([
                'status' => RecoveryIncidentStatus::Recovered,
                'fix_summary' => 'The expired worker lease was already reconciled before this deterministic recovery attempt completed.',
                'resolved_at' => now(),
                'claim_token' => null,
                'claimed_at' => null,
            ]);

            $this->audit->record('recovery.runtime_deterministic_repair_superseded', [
                'recovery_incident_id' => $incident->id,
                'deterministic_repair' => $classification['deterministic_repair'],
                'attempt_count' => $incident->fresh()->attempt_count,
            ], $incident->project, $task);

            return $incident->fresh();
        }

        if ($recovered === 0) {
            return $this->recordRuntimeAttemptFailure(
                $incident,
                $classification,
                $task,
                'The deterministic stale-worker recovery handler left the expired worker state unchanged.',
            );
        }

        $incident->update([
            'status' => RecoveryIncidentStatus::Recovered,
            'fix_summary' => "Deterministic stale-worker recovery reconciled {$recovered} stale execution(s).",
            'resolved_at' => now(),
            'claim_token' => null,
            'claimed_at' => null,
        ]);

        $this->audit->record('recovery.runtime_deterministic_repair_completed', [
            'recovery_incident_id' => $incident->id,
            'deterministic_repair' => $classification['deterministic_repair'],
            'recovered_count' => $recovered,
            'attempt_count' => $incident->fresh()->attempt_count,
        ], $incident->project, $task);

        return $incident->fresh();
    }

    /**
     * Confirm that the worker referenced by a stale-worker incident still requires recovery.
     */
    private function staleWorkerIncidentStillActionable(RecoveryIncident $incident): bool
    {
        return $incident->agentWorker()
            ->where('status', 'working')
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now())
            ->exists();
    }

    /**
     * Persist a failed runtime recovery attempt and apply the bounded retry/circuit-breaker policy.
     *
     * @param  array<string, mixed>  $classification
     */
    private function recordRuntimeAttemptFailure(
        RecoveryIncident $incident,
        array $classification,
        ?Task $task,
        string $reason,
    ): RecoveryIncident {
        $incident = $incident->fresh();

        $noProgress = $this->noProgress->runtimeRecoveryFailure(
            $incident,
            [
                'classification' => $classification['category'],
                'reason' => $reason,
            ],
        );

        $maxAttempts = max(1, (int) config('aios.recovery_max_attempts'));

        $this->audit->record('recovery.runtime_attempt_failed', [
            'recovery_incident_id' => $incident->id,
            'classification' => $classification['category'],
            'attempt_count' => $incident->attempt_count,
            'retry_limit' => $maxAttempts,
            'reason' => $reason,
            'no_progress' => $noProgress,
        ], $incident->project, $task);

        if ($noProgress['detected']) {
            $this->audit->record('recovery.runtime_circuit_breaker_opened', [
                'recovery_incident_id' => $incident->id,
                'failure_fingerprint' => $noProgress['failure_fingerprint'],
                'consecutive_repeat_count' => $noProgress['consecutive_repeat_count'],
                'threshold' => $noProgress['threshold'],
                'attempt_count' => $incident->attempt_count,
            ], $incident->project, $task);

            return $this->escalateRuntime(
                $incident,
                $classification,
                $task,
                'Runtime recovery circuit breaker opened after repeated failed repairs with the same fingerprint and no material evidence change.',
            );
        }

        if ($incident->attempt_count >= $maxAttempts) {
            return $this->escalateRuntime(
                $incident,
                $classification,
                $task,
                'Bounded recovery attempt limit reached.',
            );
        }

        $incident->update([
            'status' => RecoveryIncidentStatus::Detected,
            'claim_token' => null,
            'claimed_at' => null,
        ]);

        $this->audit->record('recovery.runtime_retry_scheduled', [
            'recovery_incident_id' => $incident->id,
            'attempt_count' => $incident->attempt_count,
            'retry_limit' => $maxAttempts,
            'failure_fingerprint' => $noProgress['failure_fingerprint'],
        ], $incident->project, $task);

        return $incident->fresh();
    }

    /**
     * Close a runtime incident that does not contain sufficient safe identity for recovery.
     */
    private function closeNonActionableRuntimeIncident(
        RecoveryIncident $incident,
        ?Task $task,
    ): RecoveryIncident {
        $incident->update([
            'status' => RecoveryIncidentStatus::Recovered,
            'recoverable' => false,
            'fix_summary' => 'No automatic recovery action was taken because deterministic runtime identity was unavailable.',
            'resolved_at' => now(),
            'claim_token' => null,
            'claimed_at' => null,
        ]);

        $this->audit->record('recovery.runtime_non_actionable', [
            'recovery_incident_id' => $incident->id,
            'classification' => RuntimeRecoverabilityClassification::NonActionable->value,
        ], $incident->project, $task);

        return $incident->fresh();
    }

    /**
     * Escalate a runtime incident while preserving any existing validation and Git evidence.
     *
     * @param  array<string, mixed>  $classification
     */
    private function escalateRuntime(
        RecoveryIncident $incident,
        array $classification,
        ?Task $task,
        ?string $reasonOverride = null,
    ): RecoveryIncident {
        $incident->update([
            'status' => RecoveryIncidentStatus::Escalated,
            'recoverable' => false,
            'escalation_reason' => $reasonOverride
                ?? $classification['escalation_reason']
                ?? 'Automatic runtime recovery could not safely resolve this incident.',
            'resolved_at' => now(),
            'claim_token' => null,
            'claimed_at' => null,
        ]);

        $this->audit->record('recovery.escalated', [
            'recovery_incident_id' => $incident->id,
            'root_cause_category' => $classification['category'],
            'escalation_reason' => $incident->fresh()->escalation_reason,
        ], $incident->project, $task);

        return $incident->fresh();
    }

    /**
     * Reclaim an abandoned incident claim and interrupt its stale Recovery Engineer run.
     */
    private function reclaimIncidentClaim(RecoveryIncident $incident): RecoveryIncident
    {
        AgentRun::query()
            ->where('recovery_incident_id', $incident->id)
            ->where('status', AgentRunStatus::Running)
            ->update([
                'status' => AgentRunStatus::Interrupted,
                'finished_at' => now(),
            ]);

        $incident->update([
            'status' => RecoveryIncidentStatus::Detected,
            'claim_token' => null,
            'claimed_at' => null,
        ]);

        $task = $incident->task_id === null
            ? null
            : Task::query()->find($incident->task_id);

        $this->audit->record('recovery.claim_reclaimed', [
            'recovery_incident_id' => $incident->id,
        ], $incident->project, $task);

        return $incident->fresh();
    }

    /**
     * Return the durable runtime failure-family values used by recovery queries.
     *
     * @return list<string>
     */
    private function runtimeFailureTypes(): array
    {
        return array_map(
            static fn (RuntimeRecoveryIncidentFamily $family): string => $family->value,
            RuntimeRecoveryIncidentFamily::cases(),
        );
    }

    /**
     * Atomically claim one detected incident.
     */
    private function claim(RecoveryIncident $incident): bool
    {
        $updated = RecoveryIncident::query()
            ->whereKey($incident->id)
            ->where('status', RecoveryIncidentStatus::Detected->value)
            ->update([
                'status' => RecoveryIncidentStatus::Diagnosing->value,
                'claim_token' => (string) Str::uuid(),
                'claimed_at' => now(),
            ]);

        return $updated === 1;
    }

    /**
     * Determine whether a workflow Task remains eligible for recovery.
     */
    private function stillEligible(Task $task): bool
    {
        return in_array(
            TaskStatus::from($task->getRawOriginal('status')),
            [
                TaskStatus::Blocked,
                TaskStatus::Interrupted,
                TaskStatus::Failed,
            ],
            true,
        );
    }

    /**
     * Close an incident whose Task was already handled by another durable workflow transition.
     */
    private function resolveAlreadyHandled(
        RecoveryIncident $incident,
        Task $task,
    ): RecoveryIncident {
        $incident->update([
            'status' => RecoveryIncidentStatus::Recovered,
            'root_cause' => 'The task left its stuck state before the Workflow Recovery Engineer diagnosed it.',
            'root_cause_category' => null,
            'recoverable' => true,
            'resulting_task_transition' => TaskStatus::from(
                $task->getRawOriginal('status'),
            )->value,
            'resolved_at' => now(),
        ]);

        $this->audit->record('recovery.superseded', [
            'recovery_incident_id' => $incident->id,
            'task_status' => $task->getRawOriginal('status'),
        ], $incident->project, $task);

        return $incident->fresh();
    }

    /**
     * Classify known workflow blocks without invoking an Agent.
     *
     * @return array<string, mixed>|null
     */
    private function classifyDeterministically(?Task $task): ?array
    {
        if ($task === null) {
            return null;
        }

        $blockingEvent = $task->auditEvents()
            ->whereIn('event_type', array_keys(self::KnownDeterministicBlocks))
            ->latest('occurred_at')
            ->first();

        if ($blockingEvent === null) {
            return null;
        }

        if ($blockingEvent->event_type === 'task.blocked_dirty_repository') {
            $attribution = $this->attributeStaleAttempt($task);

            if ($attribution !== null) {
                return $attribution;
            }
        }

        $category = self::KnownDeterministicBlocks[$blockingEvent->event_type];

        return [
            'category' => $category,
            'summary' => "Task is blocked by a previously recorded [{$blockingEvent->event_type}] condition, which requires operator judgment.",
            'recoverable' => false,
            'fix_applied' => false,
            'changed_files' => [],
            'fix_summary' => null,
            'escalation_reason' => "Automatic recovery is unsafe for category [{$category}]; the condition needs operator review and manual resolution before this task can be requeued.",
        ];
    }

    /**
     * Attribute a dirty managed-project repository to one persisted abandoned TaskAttempt.
     *
     * @return array<string, mixed>|null
     */
    private function attributeStaleAttempt(Task $task): ?array
    {
        $task->loadMissing('project');

        $state = $this->git->inspect($task->project->path);

        if (! $state['inspectable'] || $state['clean']) {
            return null;
        }

        $attempt = $this->attributor->attribute($task->project, $state);

        if ($attempt === null) {
            return null;
        }

        $originTask = $attempt->task;

        return [
            'category' => 'stale_agent_attempt',
            'summary' => "The repository's uncommitted changes match task {$originTask->key} attempt #{$attempt->number}, which never committed before it was interrupted; resuming that task instead of escalating.",
            'recoverable' => true,
            'fix_applied' => false,
            'changed_files' => [],
            'fix_summary' => null,
            'escalation_reason' => null,
            'origin_task_id' => $originTask->id,
        ];
    }

    /**
     * Requeue the actual originating Task for an attributed stale attempt.
     */
    private function recoverViaOriginatingTask(
        RecoveryIncident $incident,
        Task $incidentTask,
        Task $originTask,
        string $summary,
    ): RecoveryIncident {
        $incident->update([
            'status' => RecoveryIncidentStatus::Validating,
        ]);

        $resultingStatus = $this->requeueTask($originTask);

        $this->audit->record('task.stale_attempt_reclaimed', [
            'recovery_incident_id' => $incident->id,
            'origin_task_id' => $originTask->id,
            'origin_task_key' => $originTask->key,
            'resulting_status' => $resultingStatus,
        ], $incident->project, $originTask);

        $incident->update([
            'status' => RecoveryIncidentStatus::Recovered,
            'fix_summary' => $summary,
            'resulting_task_transition' => "task_{$originTask->key}:{$resultingStatus}",
            'resolved_at' => now(),
        ]);

        $this->audit->record('recovery.recovered', [
            'recovery_incident_id' => $incident->id,
            'resulting_task_transition' => $incident->fresh()->resulting_task_transition,
        ], $incident->project, $incidentTask);

        return $incident->fresh();
    }

    /**
     * Ensure workflow diagnosis exceptions become bounded recovery evidence rather than escaping.
     *
     * @return array<string, mixed>
     */
    private function diagnoseSafely(
        RecoveryIncident $incident,
        ?Task $task,
    ): array {
        try {
            return $this->diagnoseWithRecoveryEngineer($incident, $task);
        } catch (Throwable $exception) {
            $this->audit->record('recovery.diagnosis_exception', [
                'recovery_incident_id' => $incident->id,
                'exception_class' => $exception::class,
            ], $incident->project, $task);

            return [
                'category' => 'transient_harness_failure',
                'summary' => 'The Workflow Recovery Engineer execution threw an unhandled exception.',
                'recoverable' => true,
                'fix_applied' => false,
                'changed_files' => [],
                'fix_summary' => null,
                'escalation_reason' => 'Repeated Workflow Recovery Engineer executions threw unhandled exceptions.',
            ];
        }
    }

    /**
     * Execute workflow diagnosis/repair inside a disposable worktree and validate it before live materialization.
     *
     * @return array<string, mixed>
     */
    private function diagnoseWithRecoveryEngineer(
        RecoveryIncident $incident,
        ?Task $task,
    ): array {
        try {
            $agent = $this->globalAgents->forRole(AgentRole::RecoveryEngineer);
            $this->harnesses->resolve($agent);
        } catch (LogicException $exception) {
            $reason = $this->safeRecoveryText($exception->getMessage())
                ?? 'Recovery Engineer configuration is invalid.';

            $this->audit->record('recovery.blocked_agent_misconfigured', [
                'recovery_incident_id' => $incident->id,
                'reason' => $reason,
            ], $incident->project, $task);

            return [
                'category' => 'configuration_environment',
                'summary' => 'The global Recovery Engineer Agent is disabled or misconfigured, so no diagnosis was attempted.',
                'recoverable' => false,
                'fix_applied' => false,
                'changed_files' => [],
                'fix_summary' => null,
                'escalation_reason' => $reason,
            ];
        }

        $repositoryPath = (string) config('aios.recovery_repository_path');
        $preflight = $this->lifecycle->preflight($repositoryPath);

        if (! $preflight['clean'] || $preflight['head_sha'] === null) {
            return [
                'category' => 'unsafe_git_state',
                'summary' => 'The AIOS recovery repository is not in a clean, inspectable state, so no bounded fix can safely be attempted.',
                'recoverable' => false,
                'fix_applied' => false,
                'changed_files' => [],
                'fix_summary' => null,
                'escalation_reason' => 'AIOS recovery repository preflight failed.',
            ];
        }

        try {
            $worktreePath = $this->worktrees->create(
                $repositoryPath,
                $preflight['head_sha'],
            );
        } catch (Throwable $exception) {
            $this->audit->record('recovery.blocked_worktree_isolation_failed', [
                'recovery_incident_id' => $incident->id,
                'reason' => $this->safeRecoveryText($exception->getMessage()),
            ], $incident->project, $task);

            return [
                'category' => 'unsafe_git_state',
                'summary' => 'A disposable recovery worktree could not be created, so no diagnosis was attempted against the live AIOS repository.',
                'recoverable' => false,
                'fix_applied' => false,
                'changed_files' => [],
                'fix_summary' => null,
                'escalation_reason' => 'Recovery worktree isolation failed.',
            ];
        }

        try {
            $project = $task === null
                ? $incident->project
                : $task->project;

            if ($project === null) {
                return [
                    'category' => 'configuration_environment',
                    'summary' => 'The recovery incident has no project scope for durable AgentRun attribution.',
                    'recoverable' => false,
                    'fix_applied' => false,
                    'changed_files' => [],
                    'fix_summary' => null,
                    'escalation_reason' => 'Recovery execution requires a durable project scope.',
                ];
            }

            $assembled = $this->contextAssembler->assemble(
                $agent,
                AgentRole::RecoveryEngineer,
                $this->buildWorkflowRecoveryContext(
                    $incident,
                    $task,
                    $preflight['head_sha'],
                ),
            );

            $prompt = $this->buildRecoveryPrompt($assembled);

            $run = $this->runs->start(
                $project,
                AgentRole::RecoveryEngineer,
                $prompt,
                $task,
                agent: $agent,
                context: $assembled,
            );

            $run->update([
                'recovery_incident_id' => $incident->id,
            ]);

            try {
                $this->databaseProtection->guard();
            } catch (DatabaseProtectionFailed $exception) {
                $this->runs->complete(
                    $run,
                    $this->failedExecution(
                        'Database protection blocked Recovery Engineer execution.',
                    ),
                );

                $reason = $this->safeRecoveryText($exception->getMessage())
                    ?? 'Database protection failed.';

                $this->audit->record('recovery.blocked_database_protection_failed', [
                    'recovery_incident_id' => $incident->id,
                    'reason' => $reason,
                ], $incident->project, $task);

                return [
                    'category' => 'configuration_environment',
                    'summary' => 'No verified database recovery point was available, so no diagnosis was attempted.',
                    'recoverable' => false,
                    'fix_applied' => false,
                    'changed_files' => [],
                    'fix_summary' => null,
                    'escalation_reason' => $reason,
                ];
            }

            try {
                $result = $this->engineer->run(
                    $agent,
                    $prompt,
                    $worktreePath,
                );
            } catch (Throwable $exception) {
                $this->runs->complete(
                    $run,
                    $this->failedExecution(
                        'Recovery Engineer execution threw ['.$exception::class.'].',
                    ),
                );

                throw $exception;
            }

            $this->runs->complete($run, $result['execution']);

            if (
                $result['execution']['exit_code'] !== 0
                || $result['decision'] === null
            ) {
                return [
                    'category' => 'transient_harness_failure',
                    'summary' => 'The Workflow Recovery Engineer execution failed or returned no structured decision.',
                    'recoverable' => true,
                    'fix_applied' => false,
                    'changed_files' => [],
                    'fix_summary' => null,
                    'escalation_reason' => 'Repeated Workflow Recovery Engineer executions failed or returned no structured decision.',
                ];
            }

            $proposal = $this->inspectRecoveryProposal(
                $result['decision'],
                $run->fresh(),
                $worktreePath,
                $repositoryPath,
                $preflight['head_sha'],
            );

            if (! $proposal['passed']) {
                return [
                    'category' => $proposal['failure_category'],
                    'summary' => $proposal['reason']
                        ?? 'The Recovery Engineer proposal failed AIOS validation.',
                    'recoverable' => false,
                    'fix_applied' => false,
                    'changed_files' => $proposal['changed_files'],
                    'fix_summary' => null,
                    'escalation_reason' => $proposal['reason']
                        ?? 'The Recovery Engineer proposal could not be trusted.',
                    'validation_evidence' => $proposal['validation'],
                ];
            }

            return [
                'category' => $proposal['decision']['root_cause_category'],
                'summary' => $proposal['decision']['root_cause_summary'],
                'recoverable' => $proposal['decision']['recoverable'],
                'fix_applied' => $proposal['decision']['fix_applied'],
                'changed_files' => $proposal['changed_files'],
                'fix_summary' => $proposal['decision']['fix_summary'],
                'escalation_reason' => $proposal['decision']['escalation_reason'],
                'base_sha' => $preflight['head_sha'],
                'validation_evidence' => $proposal['validation'],
            ];
        } finally {
            $this->worktrees->destroy($repositoryPath, $worktreePath);
        }
    }

    /**
     * Validate untrusted Recovery Engineer output, derive the real worktree diff, validate it,
     * and materialize only the AIOS-derived change set after the live repository is rechecked.
     *
     * @param  array<string, mixed>  $decision
     * @return array{
     *     passed: bool,
     *     failure_category: string,
     *     reason: ?string,
     *     decision: ?array<string, mixed>,
     *     changed_files: list<string>,
     *     validation: array<string, mixed>
     * }
     */
    private function inspectRecoveryProposal(
        array $decision,
        AgentRun $run,
        string $worktreePath,
        string $repositoryPath,
        string $baseSha,
    ): array {
        $validation = [
            'checks' => [],
            'evidence' => [],
        ];

        try {
            $validated = validator($decision, [
                'root_cause_category' => [
                    'required',
                    'in:application_defect,orchestration_defect,configuration_environment,transient_harness_failure,stale_lease,validation_failure,unsafe_git_state,managed_project_defect',
                ],
                'root_cause_summary' => ['required', 'string', 'max:8000'],
                'recoverable' => ['required', 'boolean'],
                'fix_applied' => ['required', 'boolean'],
                'changed_files' => ['present', 'array'],
                'changed_files.*' => ['required', 'string', 'max:512'],
                'fix_summary' => ['nullable', 'string', 'max:8000'],
                'escalation_reason' => ['nullable', 'string', 'max:8000'],
            ])->validate();
        } catch (ValidationException) {
            $validation['checks']['structured_decision'] = false;

            return $this->failedRecoveryProposal(
                'The Recovery Engineer returned an invalid structured decision.',
                $validation,
                failureCategory: 'orchestration_defect',
            );
        }

        $validation['checks']['structured_decision'] = true;

        $rootCauseSummary = $this->safeRecoveryText(
            $validated['root_cause_summary'],
        );

        $fixSummary = is_string($validated['fix_summary'] ?? null)
            ? $this->safeRecoveryText($validated['fix_summary'])
            : null;

        $escalationReason = is_string($validated['escalation_reason'] ?? null)
            ? $this->safeRecoveryText($validated['escalation_reason'])
            : null;

        if ($rootCauseSummary === null) {
            return $this->failedRecoveryProposal(
                'The Recovery Engineer root-cause summary became empty after sanitization.',
                $validation,
                failureCategory: 'orchestration_defect',
            );
        }

        $fixApplied = (bool) $validated['fix_applied'];
        $recoverable = (bool) $validated['recoverable'];

        if ($fixApplied && $fixSummary === null) {
            return $this->failedRecoveryProposal(
                'A Recovery Engineer proposal that applies a fix must include a bounded fix summary.',
                $validation,
                failureCategory: 'orchestration_defect',
            );
        }

        if (! $recoverable && $escalationReason === null) {
            return $this->failedRecoveryProposal(
                'A non-recoverable Recovery Engineer decision must include an escalation reason.',
                $validation,
                failureCategory: 'orchestration_defect',
            );
        }

        if (! $recoverable && $fixApplied) {
            return $this->failedRecoveryProposal(
                'A non-recoverable Recovery Engineer decision cannot simultaneously claim that a fix was applied.',
                $validation,
                failureCategory: 'orchestration_defect',
            );
        }

        $declaredChangedFiles = $this->normalizeDeclaredChangedFiles(
            $validated['changed_files'],
        );

        if ($declaredChangedFiles === null) {
            $validation['checks']['declared_change_paths'] = false;

            return $this->failedRecoveryProposal(
                'The Recovery Engineer declared an unsafe or duplicate changed-file path.',
                $validation,
            );
        }

        $validation['checks']['declared_change_paths'] = true;

        $forbiddenGitCommands = $this->forbiddenRecoveryGitCommands($run);
        $validation['checks']['forbidden_git_commands'] = $forbiddenGitCommands === [];
        $validation['evidence']['forbidden_git_commands'] = $forbiddenGitCommands;

        if ($forbiddenGitCommands !== []) {
            return $this->failedRecoveryProposal(
                'The Recovery Engineer attempted a prohibited Git lifecycle command.',
                $validation,
                $declaredChangedFiles,
            );
        }

        $worktreeState = $this->lifecycle->preflight($worktreePath);
        $worktreeHeadUnchanged = $worktreeState['head_sha'] === $baseSha;

        $validation['checks']['worktree_head_unchanged'] = $worktreeHeadUnchanged;
        $validation['evidence']['worktree_head_sha'] = $worktreeState['head_sha'];

        if (! $worktreeHeadUnchanged) {
            return $this->failedRecoveryProposal(
                'The Recovery Engineer worktree HEAD moved away from the AIOS-recorded base SHA.',
                $validation,
                $declaredChangedFiles,
            );
        }

        $actualChangedFiles = $this->lifecycle->changedFilesFromBase(
            $worktreePath,
            $baseSha,
        );

        $validation['checks']['worktree_change_set'] = $actualChangedFiles !== null;
        $validation['evidence']['worktree_changed_files'] = $actualChangedFiles ?? [];

        if ($actualChangedFiles === null) {
            return $this->failedRecoveryProposal(
                'AIOS could not independently derive the Recovery Engineer worktree change set.',
                $validation,
                $declaredChangedFiles,
            );
        }

        foreach ($actualChangedFiles as $actualChangedFile) {
            if (! $this->isSafeRecoveryPath($actualChangedFile)) {
                $validation['checks']['actual_change_paths'] = false;

                return $this->failedRecoveryProposal(
                    'The AIOS-derived worktree change set contains an unsafe relative path.',
                    $validation,
                    $actualChangedFiles,
                );
            }
        }

        $validation['checks']['actual_change_paths'] = true;

        $declaredMatchesActual = $declaredChangedFiles === $actualChangedFiles;
        $changeShapeValid = $fixApplied
            ? $actualChangedFiles !== [] && $declaredMatchesActual
            : $actualChangedFiles === [] && $declaredChangedFiles === [];

        $validation['checks']['declared_change_set_matches_actual'] = $declaredMatchesActual;
        $validation['checks']['fix_applied_matches_actual_changes'] = $changeShapeValid;

        if (! $changeShapeValid) {
            return $this->failedRecoveryProposal(
                'Recovery Engineer fix_applied/changed_files did not exactly match the AIOS-derived worktree changes.',
                $validation,
                $actualChangedFiles,
            );
        }

        $validatedDecision = [
            'root_cause_category' => (string) $validated['root_cause_category'],
            'root_cause_summary' => $rootCauseSummary,
            'recoverable' => $recoverable,
            'fix_applied' => $fixApplied,
            'changed_files' => $actualChangedFiles,
            'fix_summary' => $fixSummary,
            'escalation_reason' => $escalationReason,
        ];

        if (! $fixApplied) {
            return [
                'passed' => true,
                'failure_category' => 'validation_failure',
                'reason' => null,
                'decision' => $validatedDecision,
                'changed_files' => [],
                'validation' => $validation,
            ];
        }

        $isolatedValidation = $this->lifecycle->validate(
            $worktreePath,
            $actualChangedFiles,
        );

        $validation = $this->appendValidationStage(
            $validation,
            'isolated_validation',
            $isolatedValidation,
        );

        if (! $isolatedValidation['passed']) {
            return $this->failedRecoveryProposal(
                'The proposed Recovery Engineer fix failed validation inside the disposable worktree.',
                $validation,
                $actualChangedFiles,
            );
        }

        $liveState = $this->lifecycle->preflight($repositoryPath);
        $liveRepositoryUnchanged = $liveState['clean']
            && $liveState['head_sha'] === $baseSha;

        $validation['checks']['live_repository_unchanged'] = $liveRepositoryUnchanged;
        $validation['evidence']['live_repository_head_sha'] = $liveState['head_sha'];

        if (! $liveRepositoryUnchanged) {
            return $this->failedRecoveryProposal(
                'The live AIOS repository changed after the Recovery Engineer attempt began.',
                $validation,
                $actualChangedFiles,
            );
        }

        $materialized = $this->copyChangedFilesIntoRepository(
            $worktreePath,
            $repositoryPath,
            $actualChangedFiles,
        );

        $validation['checks']['materialization'] = $materialized;

        if (! $materialized) {
            return $this->failedRecoveryProposal(
                'AIOS could not safely materialize the validated worktree changes into the recovery repository.',
                $validation,
                $actualChangedFiles,
            );
        }

        $liveChangedFiles = $this->lifecycle->changedFilesFromBase(
            $repositoryPath,
            $baseSha,
        );

        $liveChangeSetMatches = $liveChangedFiles === $actualChangedFiles;

        $validation['checks']['live_change_set_matches'] = $liveChangeSetMatches;
        $validation['evidence']['live_changed_files'] = $liveChangedFiles ?? [];

        if (! $liveChangeSetMatches) {
            return $this->failedRecoveryProposal(
                'The materialized live change set does not exactly match the validated isolated worktree change set.',
                $validation,
                $actualChangedFiles,
            );
        }

        return [
            'passed' => true,
            'failure_category' => 'validation_failure',
            'reason' => null,
            'decision' => $validatedDecision,
            'changed_files' => $actualChangedFiles,
            'validation' => $validation,
        ];
    }

    /**
     * Build a uniform failed proposal result.
     *
     * @param  array<string, mixed>  $validation
     * @param  list<string>  $changedFiles
     * @return array{
     *     passed: false,
     *     failure_category: string,
     *     reason: string,
     *     decision: null,
     *     changed_files: list<string>,
     *     validation: array<string, mixed>
     * }
     */
    private function failedRecoveryProposal(
        string $reason,
        array $validation,
        array $changedFiles = [],
        string $failureCategory = 'validation_failure',
    ): array {
        $validation['evidence'] = is_array($validation['evidence'] ?? null)
            ? $validation['evidence']
            : [];

        $validation['evidence']['failure_reason'] = $reason;

        return [
            'passed' => false,
            'failure_category' => $failureCategory,
            'reason' => $reason,
            'decision' => null,
            'changed_files' => $changedFiles,
            'validation' => $validation,
        ];
    }

    /**
     * Normalize and validate the Agent-declared file list without silently fixing unsafe values.
     *
     * @param  array<int, mixed>  $files
     * @return list<string>|null
     */
    private function normalizeDeclaredChangedFiles(array $files): ?array
    {
        $normalized = [];
        $seen = [];

        foreach ($files as $file) {
            if (! is_string($file) || ! $this->isSafeRecoveryPath($file)) {
                return null;
            }

            if (isset($seen[$file])) {
                return null;
            }

            $seen[$file] = true;
            $normalized[] = $file;
        }

        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Validate one repository-relative Recovery Engineer path.
     */
    private function isSafeRecoveryPath(string $path): bool
    {
        if (
            $path === ''
            || Str::length($path) > self::RecoveryPathLimit
            || Str::contains($path, ["\0", '\\', '//'])
            || Str::startsWith($path, '/')
        ) {
            return false;
        }

        $segments = explode('/', $path);

        if ($segments[0] === '.git') {
            return false;
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Detect prohibited Agent-owned Git lifecycle commands from durable harness command evidence.
     *
     * @return list<string>
     */
    private function forbiddenRecoveryGitCommands(AgentRun $run): array
    {
        $commands = $run->getAttribute('commands');

        if (! is_array($commands)) {
            return [];
        }

        $forbidden = [];

        foreach ($commands as $commandEvidence) {
            if (! is_array($commandEvidence)) {
                continue;
            }

            $command = $commandEvidence['command'] ?? null;

            if (! is_string($command)) {
                continue;
            }

            if (
                preg_match(
                    '~(?:^|[\s;&|])(?:[^\s;&|]*/)?git\s+(add|commit|reset|stash|checkout|switch|merge|rebase|cherry-pick|clean)\b~i',
                    $command,
                    $matches,
                ) !== 1
            ) {
                continue;
            }

            $forbidden[] = 'git '.Str::lower($matches[1]);
        }

        return array_values(array_unique($forbidden));
    }

    /**
     * Materialize only AIOS-derived validated paths from an isolated worktree.
     *
     * @param  list<string>  $changedFiles
     */
    private function copyChangedFilesIntoRepository(
        string $worktreePath,
        string $repositoryPath,
        array $changedFiles,
    ): bool {
        $worktreeRoot = realpath($worktreePath);
        $repositoryRoot = realpath($repositoryPath);

        if ($worktreeRoot === false || $repositoryRoot === false) {
            return false;
        }

        foreach ($changedFiles as $relative) {
            if (! $this->isSafeRecoveryPath($relative)) {
                return false;
            }

            if (
                $this->pathHasSymlinkParent($worktreeRoot, $relative)
                || $this->pathHasSymlinkParent($repositoryRoot, $relative)
            ) {
                return false;
            }

            $source = $worktreeRoot.DIRECTORY_SEPARATOR.$relative;
            $destination = $repositoryRoot.DIRECTORY_SEPARATOR.$relative;

            if (! file_exists($source) && ! is_link($source)) {
                if (is_dir($destination) && ! is_link($destination)) {
                    return false;
                }

                if (! is_file($destination) && ! is_link($destination)) {
                    return false;
                }

                if (! @unlink($destination)) {
                    return false;
                }

                continue;
            }

            if (is_link($source) || is_link($destination)) {
                return false;
            }

            $resolvedSource = realpath($source);

            if (
                $resolvedSource === false
                || ! Str::startsWith(
                    $resolvedSource,
                    $worktreeRoot.DIRECTORY_SEPARATOR,
                )
                || ! is_file($resolvedSource)
            ) {
                return false;
            }

            if (is_dir($destination)) {
                return false;
            }

            try {
                File::ensureDirectoryExists(dirname($destination));
            } catch (Throwable) {
                return false;
            }

            $resolvedDestinationDirectory = realpath(dirname($destination));

            if (
                $resolvedDestinationDirectory === false
                || (
                    $resolvedDestinationDirectory !== $repositoryRoot
                    && ! Str::startsWith(
                        $resolvedDestinationDirectory,
                        $repositoryRoot.DIRECTORY_SEPARATOR,
                    )
                )
            ) {
                return false;
            }

            if (! @copy($resolvedSource, $destination)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Detect symlink or non-directory parent components before a copy/unlink operation.
     */
    private function pathHasSymlinkParent(string $root, string $relative): bool
    {
        $segments = explode('/', $relative);
        array_pop($segments);

        $cursor = $root;

        foreach ($segments as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($cursor)) {
                return true;
            }

            if (file_exists($cursor) && ! is_dir($cursor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply a workflow Recovery Engineer fix through the existing AIOS validation and commit lifecycle.
     *
     * @param  array<string, mixed>  $classification
     */
    private function applyRepair(
        RecoveryIncident $incident,
        array $classification,
        ?Task $task,
    ): RecoveryIncident {
        $repositoryPath = (string) config('aios.recovery_repository_path');
        $baseSha = $classification['base_sha'] ?? null;

        $validationEvidence = is_array(
            $classification['validation_evidence'] ?? null,
        )
            ? $classification['validation_evidence']
            : [
                'checks' => [],
                'evidence' => [],
            ];

        $liveValidation = $baseSha === null
            ? [
                'passed' => false,
                'checks' => ['base_sha' => false],
                'evidence' => [],
            ]
            : $this->lifecycle->validate(
                $repositoryPath,
                $classification['changed_files'],
            );

        $validationEvidence = $this->appendValidationStage(
            $validationEvidence,
            'live_validation',
            $liveValidation,
        );

        if (! $liveValidation['passed']) {
            $incident->update([
                'status' => RecoveryIncidentStatus::Validating,
            ]);

            return $this->escalate(
                $incident,
                $classification,
                $task,
                'The proposed fix failed AIOS-independent validation.',
                $validationEvidence,
            );
        }

        $incident->update([
            'status' => RecoveryIncidentStatus::Repairing,
        ]);

        $commitSha = $this->lifecycle->commit(
            $repositoryPath,
            $classification['changed_files'],
            (string) $baseSha,
            "recovery: incident #{$incident->id} ({$incident->failure_type})",
        );

        $validationEvidence['checks']['commit'] = $commitSha !== null;

        if ($commitSha === null) {
            return $this->escalate(
                $incident,
                $classification,
                $task,
                'The validated fix could not be committed to the AIOS recovery repository.',
                $validationEvidence,
            );
        }

        $incident->update([
            'status' => RecoveryIncidentStatus::Validating,
            'base_sha' => $baseSha,
            'head_sha' => $commitSha,
            'commit_sha' => $commitSha,
            'changed_files' => $classification['changed_files'],
            'validation_evidence' => $validationEvidence,
            'fix_summary' => $classification['fix_summary'],
        ]);

        $this->audit->record('recovery.fix_committed', [
            'recovery_incident_id' => $incident->id,
            'base_sha' => $baseSha,
            'commit_sha' => $commitSha,
            'changed_files' => $classification['changed_files'],
        ], $incident->project, $task);

        return $this->recover(
            $incident,
            $task,
            $classification['fix_summary'] ?? $classification['summary'],
        );
    }

    /**
     * Commit a validated runtime repair without changing Task workflow state.
     *
     * @param  array<string, mixed>  $classification
     * @param  array<string, mixed>  $proposal
     */
    private function applyRuntimeRepair(
        RecoveryIncident $incident,
        array $classification,
        array $proposal,
        ?Task $task,
    ): RecoveryIncident {
        $repositoryPath = (string) config('aios.recovery_repository_path');
        $baseSha = (string) $incident->base_sha;
        $changedFiles = $proposal['changed_files'];
        $validationEvidence = $proposal['validation'];

        $incident->update([
            'status' => RecoveryIncidentStatus::Validating,
            'base_sha' => $baseSha,
            'changed_files' => $changedFiles,
            'validation_evidence' => $validationEvidence,
        ]);

        $liveValidation = $this->lifecycle->validate(
            $repositoryPath,
            $changedFiles,
        );

        $validationEvidence = $this->appendValidationStage(
            $validationEvidence,
            'live_validation',
            $liveValidation,
        );

        if (! $liveValidation['passed']) {
            $incident->update([
                'validation_evidence' => $validationEvidence,
            ]);

            return $this->recordRuntimeAttemptFailure(
                $incident->fresh(),
                $classification,
                $task,
                'The materialized runtime repair failed AIOS-independent live validation.',
            );
        }

        $commitSha = $this->lifecycle->commit(
            $repositoryPath,
            $changedFiles,
            $baseSha,
            "recovery: incident #{$incident->id} ({$incident->failure_type})",
        );

        $validationEvidence['checks']['commit'] = $commitSha !== null;

        if ($commitSha === null) {
            $incident->update([
                'validation_evidence' => $validationEvidence,
            ]);

            return $this->recordRuntimeAttemptFailure(
                $incident->fresh(),
                $classification,
                $task,
                'The validated runtime repair could not be committed by AIOS.',
            );
        }

        $decision = $proposal['decision'];

        $incident->update([
            'status' => RecoveryIncidentStatus::Recovered,
            'root_cause' => $decision['root_cause_summary'],
            'recoverable' => true,
            'fix_summary' => $decision['fix_summary'],
            'base_sha' => $baseSha,
            'head_sha' => $commitSha,
            'commit_sha' => $commitSha,
            'changed_files' => $changedFiles,
            'validation_evidence' => $validationEvidence,
            'resolved_at' => now(),
            'claim_token' => null,
            'claimed_at' => null,
        ]);

        $this->audit->record('recovery.fix_committed', [
            'recovery_incident_id' => $incident->id,
            'base_sha' => $baseSha,
            'commit_sha' => $commitSha,
            'changed_files' => $changedFiles,
        ], $incident->project, $task);

        $this->audit->record('recovery.runtime_ai_repair_completed', [
            'recovery_incident_id' => $incident->id,
            'attempt_count' => $incident->fresh()->attempt_count,
            'policy_classification' => RuntimeRecoverabilityClassification::CandidateAiRepair->value,
            'agent_root_cause_category' => $decision['root_cause_category'],
            'commit_sha' => $commitSha,
            'changed_files' => $changedFiles,
        ], $incident->project, $task);

        $this->audit->record('recovery.recovered', [
            'recovery_incident_id' => $incident->id,
            'resulting_task_transition' => null,
        ], $incident->project, $task);

        return $incident->fresh();
    }

    /**
     * Complete workflow recovery and requeue the affected Task when applicable.
     */
    private function recover(
        RecoveryIncident $incident,
        ?Task $task,
        ?string $fixSummary,
    ): RecoveryIncident {
        $incident->update([
            'status' => RecoveryIncidentStatus::Validating,
        ]);

        $resultingTransition = $task === null
            ? null
            : $this->requeueTask($task);

        $incident->update([
            'status' => RecoveryIncidentStatus::Recovered,
            'fix_summary' => $fixSummary,
            'resulting_task_transition' => $resultingTransition,
            'resolved_at' => now(),
        ]);

        $this->audit->record('recovery.recovered', [
            'recovery_incident_id' => $incident->id,
            'resulting_task_transition' => $resultingTransition,
        ], $incident->project, $task);

        return $incident->fresh();
    }

    /**
     * Requeue a workflow Task through the existing durable Task state machine.
     */
    private function requeueTask(Task $task): string
    {
        $status = TaskStatus::from($task->getRawOriginal('status'));

        return match ($status) {
            TaskStatus::Blocked => $this->transitionAndReturn(
                $task,
                TaskStatus::Queued,
            ),
            TaskStatus::Interrupted => $this->transitionAndReturn(
                $task,
                TaskStatus::Failed,
            ),
            default => $status->value,
        };
    }

    /**
     * Apply one workflow transition and return its durable status value.
     */
    private function transitionAndReturn(Task $task, TaskStatus $to): string
    {
        $this->workflow->transition($task, $to);

        return $to->value;
    }

    /**
     * Escalate one workflow incident.
     *
     * @param  array<string, mixed>  $classification
     * @param  array<string, mixed>|null  $validationEvidence
     */
    private function escalate(
        RecoveryIncident $incident,
        array $classification,
        ?Task $task,
        ?string $reasonOverride = null,
        ?array $validationEvidence = null,
    ): RecoveryIncident {
        $incident->update([
            'status' => RecoveryIncidentStatus::Escalated,
            'recoverable' => false,
            'escalation_reason' => $reasonOverride
                ?? $classification['escalation_reason']
                ?? 'Automatic recovery could not safely resolve this incident.',
            'validation_evidence' => $validationEvidence,
            'resolved_at' => now(),
        ]);

        $this->audit->record('recovery.escalated', [
            'recovery_incident_id' => $incident->id,
            'root_cause_category' => $classification['category'],
            'escalation_reason' => $incident->fresh()->escalation_reason,
        ], $incident->project, $task);

        return $incident->fresh();
    }

    /**
     * Build a bounded workflow Recovery Engineer task context.
     *
     * @return array<string, mixed>
     */
    private function buildWorkflowRecoveryContext(
        RecoveryIncident $incident,
        ?Task $task,
        string $baseSha,
    ): array {
        return $this->boundedSanitizedPayload([
            'recovery_mode' => 'workflow',
            'objective' => 'Diagnose the workflow failure and apply a minimal AIOS code fix only when the defect is safely repairable inside AIOS itself.',
            'repository_base_sha' => $baseSha,
            'incident' => [
                'id' => $incident->id,
                'failure_type' => $incident->failure_type,
                'status' => $incident->getRawOriginal('status'),
                'attempt_count' => $incident->attempt_count,
                'evidence' => $incident->evidence ?? [],
            ],
            'project' => $incident->project === null
                ? null
                : [
                    'id' => $incident->project->id,
                    'name' => $incident->project->name,
                ],
            'task' => $task === null
                ? null
                : [
                    'id' => $task->id,
                    'key' => $task->key,
                    'title' => $task->title,
                    'objective' => $task->objective,
                    'acceptance_criteria' => $task->acceptance_criteria,
                    'status' => $task->getRawOriginal('status'),
                ],
            'previous_recovery_attempts' => $this->boundedPreviousRecoveryAttempts(
                $incident,
            ),
        ]);
    }

    /**
     * Build a bounded runtime Recovery Engineer context from sanitized durable incident evidence only.
     *
     * @return array<string, mixed>
     */
    private function buildRuntimeRecoveryContext(
        RecoveryIncident $incident,
        ?Task $task,
        string $baseSha,
    ): array {
        return $this->boundedSanitizedPayload([
            'recovery_mode' => 'runtime',
            'objective' => 'Diagnose this bounded project-scoped runtime incident and apply the smallest safe AIOS code fix only when supported by the durable evidence.',
            'repository_base_sha' => $baseSha,
            'attempt_number' => $incident->attempt_count + 1,
            'incident' => [
                'id' => $incident->id,
                'failure_type' => $incident->failure_type,
                'fingerprint' => $incident->fingerprint,
                'source' => $incident->source,
                'exception_class' => $incident->exception_class,
                'occurrence_count' => $incident->occurrence_count,
                'first_seen_at' => $this->iso8601(
                    $incident->getAttribute('first_seen_at'),
                ),
                'last_seen_at' => $this->iso8601(
                    $incident->getAttribute('last_seen_at'),
                ),
                'evidence' => $incident->evidence ?? [],
            ],
            'project' => $incident->project === null
                ? null
                : [
                    'id' => $incident->project->id,
                    'name' => $incident->project->name,
                ],
            'task' => $task === null
                ? null
                : [
                    'id' => $task->id,
                    'key' => $task->key,
                    'title' => $task->title,
                    'status' => $task->getRawOriginal('status'),
                ],
            'current_validation_evidence' => $incident->validation_evidence ?? [],
            'previous_recovery_attempts' => $this->boundedPreviousRecoveryAttempts(
                $incident,
            ),
        ]);
    }

    /**
     * Return bounded outcome evidence from previous Recovery Engineer runs without replaying transcripts.
     *
     * @return list<array<string, mixed>>
     */
    private function boundedPreviousRecoveryAttempts(
        RecoveryIncident $incident,
    ): array {
        $attempts = [];

        $runs = $incident->recoveryRuns()
            ->latest('started_at')
            ->limit(self::RecoveryHistoryLimit)
            ->get();

        foreach ($runs as $run) {
            $fileModifications = [];
            $modifications = $run->getAttribute('file_modifications');

            if (is_array($modifications)) {
                foreach (
                    array_slice(
                        array_values($modifications),
                        0,
                        20,
                    ) as $modification
                ) {
                    if (! is_array($modification)) {
                        continue;
                    }

                    $modificationPath = $modification['path'] ?? null;
                    $kind = $modification['kind'] ?? null;

                    if (
                        ! is_string($modificationPath)
                        || ! is_string($kind)
                    ) {
                        continue;
                    }

                    $fileModifications[] = [
                        'path' => $modificationPath,
                        'kind' => $kind,
                    ];
                }
            }

            $attempts[] = [
                'status' => $run->getRawOriginal('status'),
                'exit_code' => $run->exit_code,
                'failure_summary' => $this->runs->failureReason($run),
                'file_modifications' => $fileModifications,
                'finished_at' => $this->iso8601(
                    $run->getAttribute('finished_at'),
                ),
            ];
        }

        return $attempts;
    }

    /**
     * Normalize one Eloquent datetime attribute for bounded recovery context.
     */
    private function iso8601(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface
            ? $value->format(DATE_ATOM)
            : null;
    }

    /**
     * Build the provider-facing Recovery Engineer prompt around an immutable assembled context.
     */
    private function buildRecoveryPrompt(
        AssembledAgentContext $context,
    ): string {
        $contract = <<<'TEXT'
You are the AIOS Workflow Recovery Engineer, a system-level reliability role distinct from Project Manager, Coder, and Reviewer. Read AGENTS.md, MASTER-PROMPT.md, CLAUDE.md when applicable, and the relevant repository rules first.

You are executing only inside an AIOS-owned disposable recovery worktree. Never modify the managed project. Never run or control git add, git commit, git reset, git stash, git checkout, git switch, git merge, git rebase, git cherry-pick, or git clean. AIOS independently derives the actual diff, validates it, decides whether it may enter the live recovery repository, and owns every commit and durable state transition.

Return exactly one JSON object with:
root_cause_category: one of application_defect, orchestration_defect, configuration_environment, transient_harness_failure, stale_lease, validation_failure, unsafe_git_state, managed_project_defect
root_cause_summary: string
recoverable: boolean
fix_applied: boolean
changed_files: array of repository-relative file paths
fix_summary: string|null
escalation_reason: string|null

Set fix_applied=true only when you actually changed files in this disposable worktree. changed_files must exactly describe those edits. If automatic repair is unsafe or unsupported by the available evidence, do not guess.
TEXT;

        return $contract."\n\n".json_encode(
            $context->toArray(),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Sanitize and deterministically bound a recovery context before Agent assembly.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function boundedSanitizedPayload(array $payload): array
    {
        $bounded = $this->boundedContextValue(
            $this->sanitizer->sanitizePayload($payload),
        );

        return is_array($bounded) ? $bounded : [];
    }

    /**
     * Deterministically bound nested context depth, item count, and string length.
     */
    private function boundedContextValue(
        mixed $value,
        int $depth = 0,
    ): mixed {
        if ($depth >= self::RecoveryContextDepthLimit) {
            return '[TRUNCATED]';
        }

        if (is_string($value)) {
            return Str::limit(
                $value,
                self::RecoveryContextStringLimit,
                '',
            );
        }

        if (! is_array($value)) {
            return is_scalar($value) || $value === null
                ? $value
                : null;
        }

        $bounded = [];
        $count = 0;

        foreach ($value as $key => $nestedValue) {
            if ($count >= self::RecoveryContextArrayLimit) {
                break;
            }

            $bounded[$key] = $this->boundedContextValue(
                $nestedValue,
                $depth + 1,
            );

            $count++;
        }

        return $bounded;
    }

    /**
     * Sanitize and bound Agent-authored summaries before durable persistence.
     */
    private function safeRecoveryText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $sanitized = Str::squish(
            $this->sanitizer->sanitizeText($text),
        );

        if ($sanitized === '') {
            return null;
        }

        return Str::limit(
            $sanitized,
            self::RecoverySummaryLimit,
            '',
        );
    }

    /**
     * Append one deterministic validation stage to persisted validation evidence.
     *
     * @param  array<string, mixed>  $validation
     * @param  array<string, mixed>  $stage
     * @return array<string, mixed>
     */
    private function appendValidationStage(
        array $validation,
        string $name,
        array $stage,
    ): array {
        $validation['checks'] = is_array($validation['checks'] ?? null)
            ? $validation['checks']
            : [];

        $validation['evidence'] = is_array($validation['evidence'] ?? null)
            ? $validation['evidence']
            : [];

        $validation['checks'][$name] = (bool) ($stage['passed'] ?? false);
        $validation['evidence'][$name] = $stage;

        return $validation;
    }

    /**
     * Build a sanitized synthetic failed execution result for thrown pre/provider failures.
     *
     * @return array{exit_code: int, output: string, error_output: string}
     */
    private function failedExecution(string $reason): array
    {
        return [
            'exit_code' => -1,
            'output' => '',
            'error_output' => $reason,
        ];
    }
}
