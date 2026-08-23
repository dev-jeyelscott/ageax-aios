<?php

namespace App\Services;

use App\AgentRole;
use App\AgentRunStatus;
use App\Exceptions\DatabaseProtectionFailed;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Task;
use App\RecoveryIncidentStatus;
use App\RuntimeRecoverabilityClassification;
use App\RuntimeRecoveryIncidentFamily;
use App\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * Repair half of the Workflow Recovery Engineer. Claims one open RecoveryIncident at a time
 * (an atomic conditional update guards against a second scan or process claiming the same
 * incident), classifies its root cause, and either applies a bounded, AIOS-committed fix or
 * escalates. AIOS alone performs every durable state transition; the Recovery Engineer's LLM
 * execution only diagnoses and, when invoked, edits files inside a disposable working tree that
 * AIOS independently validates before ever committing or touching task state.
 */
class WorkflowRecoveryEngine
{
    private const array KnownDeterministicBlocks = [
        'task.blocked_dirty_repository' => 'unsafe_git_state',
        'task.blocked_agent_misconfigured' => 'configuration_environment',
        'task.blocked_unsafe_path' => 'configuration_environment',
    ];

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
    ) {}

    /**
     * Reclaim incidents whose Diagnosing/Repairing/Validating claim was abandoned by a Recovery
     * Engineer execution that itself died mid-run (e.g. the harness process was killed or ran out
     * of resources), so they are not silently orphaned forever: processOpenIncidents() only ever
     * re-scans incidents still in Detected.
     */
    public function reclaimStaleClaims(Project $project): void
    {
        $staleAfterSeconds = max(1, (int) config('aios.recovery_claim_stale_after_seconds'));

        RecoveryIncident::query()
            ->whereBelongsTo($project)
            ->whereIn('status', [RecoveryIncidentStatus::Diagnosing, RecoveryIncidentStatus::Repairing, RecoveryIncidentStatus::Validating])
            ->where('claimed_at', '<=', now()->subSeconds($staleAfterSeconds))
            ->get()
            ->each(fn (RecoveryIncident $incident): RecoveryIncident => $this->reclaimIncidentClaim($incident));
    }

    /**
     * Reclaim stale claims for global runtime incidents that have no provable managed-project scope.
     */
    public function reclaimStaleUnscopedRuntimeClaims(): void
    {
        $staleAfterSeconds = max(1, (int) config('aios.recovery_claim_stale_after_seconds'));

        RecoveryIncident::query()
            ->whereNull('project_id')
            ->whereIn('failure_type', $this->runtimeFailureTypes())
            ->whereIn('status', [RecoveryIncidentStatus::Diagnosing, RecoveryIncidentStatus::Repairing, RecoveryIncidentStatus::Validating])
            ->where('claimed_at', '<=', now()->subSeconds($staleAfterSeconds))
            ->get()
            ->each(fn (RecoveryIncident $incident): RecoveryIncident => $this->reclaimIncidentClaim($incident));
    }

    /** Process every open, unclaimed incident for a project, one at a time (strict serial recovery). */
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
     * Process unscoped runtime incidents once per scheduled recovery pass so they cannot remain invisible forever.
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
     * Process one workflow or runtime incident through the single AIOS-owned recovery lifecycle.
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
        $task = $incident->task_id === null ? null : Task::query()->find($incident->task_id);

        if ($task !== null && ! $this->stillEligible($task)) {
            return $this->resolveAlreadyHandled($incident, $task);
        }

        $classification = $this->classifyDeterministically($task) ?? $this->diagnoseSafely($incident, $task);
        $maxAttempts = max(1, (int) config('aios.recovery_max_attempts'));

        DB::transaction(function () use ($incident, $classification): void {
            RecoveryIncident::query()->whereKey($incident->id)->update([
                'root_cause' => $classification['summary'],
                'root_cause_category' => $classification['category'],
                'recoverable' => $classification['recoverable'],
                'attempt_count' => $incident->attempt_count + 1,
            ]);
        }, attempts: 3);
        $incident = $incident->fresh();

        if ($classification['category'] === 'transient_harness_failure' && $incident->attempt_count < $maxAttempts) {
            $incident->update(['status' => RecoveryIncidentStatus::Detected]);
            $this->audit->record('recovery.retry_scheduled', ['recovery_incident_id' => $incident->id, 'attempt_count' => $incident->attempt_count, 'retry_limit' => $maxAttempts], $incident->project, $task);

            return $incident->fresh();
        }

        if (! $classification['recoverable'] || $incident->attempt_count >= $maxAttempts) {
            return $this->escalate($incident, $classification, $task, $incident->attempt_count >= $maxAttempts ? 'Bounded recovery attempt limit reached.' : null);
        }

        if ($task !== null && isset($classification['origin_task_id']) && $classification['origin_task_id'] !== $task->id) {
            $originTask = Task::query()->find($classification['origin_task_id']);
            if ($originTask !== null) {
                return $this->recoverViaOriginatingTask($incident, $task, $originTask, $classification['summary']);
            }
        }

        if ($classification['fix_applied'] && $classification['changed_files'] !== []) {
            return $this->applyRepair($incident, $classification, $task);
        }

        return $this->recover($incident, $task, $classification['summary']);
    }

    /**
     * Classify and gate one runtime incident without starting the P7-004 AI repair flow.
     */
    private function processRuntimeIncident(RecoveryIncident $incident): RecoveryIncident
    {
        $incident = $incident->fresh();

        if ($incident->status !== RecoveryIncidentStatus::Detected) {
            return $incident;
        }

        if ($incident->root_cause_category === RuntimeRecoverabilityClassification::CandidateAiRepair->value) {
            return $incident;
        }

        if (! $this->claim($incident)) {
            return $incident->fresh();
        }

        $incident = $incident->fresh();
        $task = $incident->task_id === null ? null : Task::query()->find($incident->task_id);
        $classification = $this->runtimePolicy->classify($incident);

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

        return match (RuntimeRecoverabilityClassification::from($classification['category'])) {
            RuntimeRecoverabilityClassification::KnownDeterministicRepair => $this->applyKnownRuntimeRepair($incident, $classification, $task),
            RuntimeRecoverabilityClassification::CandidateAiRepair => $this->deferRuntimeAiRepair($incident, $task),
            RuntimeRecoverabilityClassification::OperatorOnly => $this->escalateRuntime($incident, $classification, $task),
            RuntimeRecoverabilityClassification::NonActionable => $this->closeNonActionableRuntimeIncident($incident, $task),
        };
    }

    /**
     * Apply only an explicitly allowlisted AIOS deterministic runtime repair and bound failed retries.
     *
     * @param  array<string, mixed>  $classification
     */
    private function applyKnownRuntimeRepair(RecoveryIncident $incident, array $classification, ?Task $task): RecoveryIncident
    {
        $maxAttempts = max(1, (int) config('aios.recovery_max_attempts'));

        if ($incident->attempt_count >= $maxAttempts) {
            return $this->escalateRuntime($incident, $classification, $task, 'Bounded recovery attempt limit reached.');
        }

        $incident->update([
            'status' => RecoveryIncidentStatus::Repairing,
            'attempt_count' => $incident->attempt_count + 1,
        ]);
        $incident = $incident->fresh();

        if ($classification['deterministic_repair'] !== 'stale_worker_recovery' || $incident->project === null) {
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
     * Confirm that the exact expired worker referenced by the runtime incident still requires deterministic recovery.
     */
    private function staleWorkerIncidentStillActionable(RecoveryIncident $incident): bool
    {
        $worker = $incident->agentWorker()->first();

        return $worker !== null
            && $worker->status === 'working'
            && $worker->lease_expires_at !== null
            && $worker->lease_expires_at->isPast();
    }

    /**
     * Persist one failed runtime repair attempt, open the no-progress breaker when required, or schedule a bounded retry.
     *
     * @param  array<string, mixed>  $classification
     */
    private function recordRuntimeAttemptFailure(RecoveryIncident $incident, array $classification, ?Task $task, string $reason): RecoveryIncident
    {
        $incident = $incident->fresh();
        $noProgress = $this->noProgress->runtimeRecoveryFailure($incident, [
            'classification' => $classification['category'],
            'reason' => $reason,
        ]);
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
            return $this->escalateRuntime($incident, $classification, $task, 'Bounded recovery attempt limit reached.');
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
     * Park an eligible candidate for P7-004 without launching, resuming, or reusing an LLM execution in P7-003.
     */
    private function deferRuntimeAiRepair(RecoveryIncident $incident, ?Task $task): RecoveryIncident
    {
        $incident->update([
            'status' => RecoveryIncidentStatus::Detected,
            'claim_token' => null,
            'claimed_at' => null,
        ]);
        $this->audit->record('recovery.runtime_ai_repair_deferred', [
            'recovery_incident_id' => $incident->id,
            'fingerprint' => $incident->fingerprint,
            'classification' => RuntimeRecoverabilityClassification::CandidateAiRepair->value,
            'next_capability' => 'P7-004',
        ], $incident->project, $task);

        return $incident->fresh();
    }

    /**
     * Close a runtime incident that lacks the stable evidence required for any safe automated action.
     */
    private function closeNonActionableRuntimeIncident(RecoveryIncident $incident, ?Task $task): RecoveryIncident
    {
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
     * Escalate a runtime incident without discarding previously persisted validation or Git evidence.
     *
     * @param  array<string, mixed>  $classification
     */
    private function escalateRuntime(RecoveryIncident $incident, array $classification, ?Task $task, ?string $reasonOverride = null): RecoveryIncident
    {
        $incident->update([
            'status' => RecoveryIncidentStatus::Escalated,
            'recoverable' => false,
            'escalation_reason' => $reasonOverride ?? $classification['escalation_reason'] ?? 'Automatic runtime recovery could not safely resolve this incident.',
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
     * Reclaim one stale recovery claim while preserving the incident and interrupting any linked running recovery run.
     */
    private function reclaimIncidentClaim(RecoveryIncident $incident): RecoveryIncident
    {
        AgentRun::query()
            ->where('recovery_incident_id', $incident->id)
            ->where('status', AgentRunStatus::Running)
            ->update(['status' => AgentRunStatus::Interrupted, 'finished_at' => now()]);
        $incident->update([
            'status' => RecoveryIncidentStatus::Detected,
            'claim_token' => null,
            'claimed_at' => null,
        ]);
        $task = $incident->task_id === null ? null : Task::query()->find($incident->task_id);
        $this->audit->record('recovery.claim_reclaimed', [
            'recovery_incident_id' => $incident->id,
        ], $incident->project, $task);

        return $incident->fresh();
    }

    /**
     * Return every runtime failure family value for bounded runtime recovery queries.
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

    private function stillEligible(Task $task): bool
    {
        return in_array(TaskStatus::from($task->getRawOriginal('status')), [TaskStatus::Blocked, TaskStatus::Interrupted, TaskStatus::Failed], true);
    }

    private function resolveAlreadyHandled(RecoveryIncident $incident, Task $task): RecoveryIncident
    {
        $incident->update([
            'status' => RecoveryIncidentStatus::Recovered,
            'root_cause' => 'The task left its stuck state before the Workflow Recovery Engineer diagnosed it.',
            'root_cause_category' => null,
            'recoverable' => true,
            'resulting_task_transition' => TaskStatus::from($task->getRawOriginal('status'))->value,
            'resolved_at' => now(),
        ]);
        $this->audit->record('recovery.superseded', ['recovery_incident_id' => $incident->id, 'task_status' => $task->getRawOriginal('status')], $incident->project, $task);

        return $incident->fresh();
    }

    /** @return ?array{category: string, summary: string, recoverable: bool, fix_applied: bool, changed_files: array<int, string>, fix_summary: ?string, escalation_reason: ?string, origin_task_id?: int} */
    private function classifyDeterministically(?Task $task): ?array
    {
        if ($task === null) {
            return null;
        }

        $blockingEvent = $task->auditEvents()->whereIn('event_type', array_keys(self::KnownDeterministicBlocks))->latest('occurred_at')->first();
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
     * A task blocked on a dirty repository is often blocked by another task's own abandoned work,
     * not its own: if the dirty tree is fully explained by a persisted, uncommitted TaskAttempt
     * belonging to some task in the project, that origin task (not this one) is the actual thing
     * to resume. Refuses to guess: any unexplained file, or more than one equally-plausible
     * origin, falls through to the existing unconditional escalation.
     *
     * @return ?array{category: string, summary: string, recoverable: bool, fix_applied: bool, changed_files: array<int, string>, fix_summary: ?string, escalation_reason: ?string, origin_task_id: int}
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

    private function recoverViaOriginatingTask(RecoveryIncident $incident, Task $incidentTask, Task $originTask, string $summary): RecoveryIncident
    {
        $incident->update(['status' => RecoveryIncidentStatus::Validating]);
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
     * Guarantees process() always records an attempt, even when diagnosis itself throws (a harness
     * crash, an unhandled provider exception, etc.), so recovery_max_attempts always bounds retries.
     * Without this, an incident that fails before returning a classification stays claimed and gets
     * reset to Detected by reclaimStaleClaims() forever, retrying indefinitely without ever reaching
     * the attempt cap or escalating.
     *
     * @return array{category: string, summary: string, recoverable: bool, fix_applied: bool, changed_files: array<int, string>, fix_summary: ?string, escalation_reason: ?string}
     */
    private function diagnoseSafely(RecoveryIncident $incident, ?Task $task): array
    {
        try {
            return $this->diagnoseWithRecoveryEngineer($incident, $task);
        } catch (Throwable $exception) {
            $this->audit->record('recovery.diagnosis_exception', [
                'recovery_incident_id' => $incident->id,
                'exception' => $exception->getMessage(),
            ], $incident->project, $task);

            return [
                'category' => 'transient_harness_failure',
                'summary' => 'The Workflow Recovery Engineer execution threw an unhandled exception.',
                'recoverable' => true,
                'fix_applied' => false,
                'changed_files' => [],
                'fix_summary' => null,
                'escalation_reason' => 'Repeated Workflow Recovery Engineer executions threw unhandled exceptions: '.$exception->getMessage(),
            ];
        }
    }

    /** @return array{category: string, summary: string, recoverable: bool, fix_applied: bool, changed_files: array<int, string>, fix_summary: ?string, escalation_reason: ?string} */
    private function diagnoseWithRecoveryEngineer(RecoveryIncident $incident, ?Task $task): array
    {
        try {
            $agent = $this->globalAgents->forRole(AgentRole::RecoveryEngineer);
            $this->harnesses->resolve($agent);
        } catch (LogicException $exception) {
            $this->audit->record('recovery.blocked_agent_misconfigured', [
                'recovery_incident_id' => $incident->id,
                'reason' => $exception->getMessage(),
            ], $incident->project, $task);

            return [
                'category' => 'configuration_environment',
                'summary' => 'The global Recovery Engineer Agent is disabled or misconfigured, so no diagnosis was attempted.',
                'recoverable' => false,
                'fix_applied' => false,
                'changed_files' => [],
                'fix_summary' => null,
                'escalation_reason' => $exception->getMessage(),
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
                'escalation_reason' => 'AIOS recovery repository preflight failed: '.implode(' ', $preflight['errors'] ?: ['unknown error']),
            ];
        }

        try {
            $this->databaseProtection->guard();
        } catch (DatabaseProtectionFailed $exception) {
            $this->audit->record('recovery.blocked_database_protection_failed', [
                'recovery_incident_id' => $incident->id,
                'reason' => $exception->getMessage(),
            ], $incident->project, $task);

            return [
                'category' => 'configuration_environment',
                'summary' => 'No verified database recovery point was available, so no diagnosis was attempted.',
                'recoverable' => false,
                'fix_applied' => false,
                'changed_files' => [],
                'fix_summary' => null,
                'escalation_reason' => 'Database protection guard failed: '.$exception->getMessage(),
            ];
        }

        try {
            $worktreePath = $this->worktrees->create($repositoryPath, $preflight['head_sha']);
        } catch (Throwable $exception) {
            $this->audit->record('recovery.blocked_worktree_isolation_failed', [
                'recovery_incident_id' => $incident->id,
                'reason' => $exception->getMessage(),
            ], $incident->project, $task);

            return [
                'category' => 'unsafe_git_state',
                'summary' => 'A disposable recovery worktree could not be created, so no diagnosis was attempted against the live AIOS repository.',
                'recoverable' => false,
                'fix_applied' => false,
                'changed_files' => [],
                'fix_summary' => null,
                'escalation_reason' => 'Recovery worktree isolation failed: '.$exception->getMessage(),
            ];
        }

        try {
            $prompt = $this->buildPrompt($incident, $task, $preflight['head_sha']);
            $project = $task?->project;
            $run = $this->runs->start($project ?? $incident->project, AgentRole::RecoveryEngineer, $prompt, $task, agent: $agent);
            $run->update(['recovery_incident_id' => $incident->id]);
            $result = $this->engineer->run($agent, $prompt, $worktreePath);
            $this->runs->complete($run, $result['execution']);

            $candidateChangedFiles = $result['decision']['changed_files'] ?? null;
            if (is_array($candidateChangedFiles) && $candidateChangedFiles !== []) {
                $this->copyChangedFilesIntoRepository($worktreePath, $repositoryPath, $candidateChangedFiles);
            }
        } finally {
            $this->worktrees->destroy($repositoryPath, $worktreePath);
        }

        if ($result['execution']['exit_code'] !== 0 || $result['decision'] === null) {
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

        try {
            $validated = validator($result['decision'], [
                'root_cause_category' => ['required', 'in:application_defect,orchestration_defect,configuration_environment,transient_harness_failure,stale_lease,validation_failure,unsafe_git_state,managed_project_defect'],
                'root_cause_summary' => ['required', 'string'],
                'recoverable' => ['required', 'boolean'],
                'fix_applied' => ['required', 'boolean'],
                'changed_files' => ['nullable', 'array'],
                'changed_files.*' => ['string'],
                'fix_summary' => ['nullable', 'string'],
                'escalation_reason' => ['exclude_unless:recoverable,false', 'required', 'string'],
            ])->validate();
        } catch (ValidationException) {
            return [
                'category' => 'orchestration_defect',
                'summary' => 'The Workflow Recovery Engineer returned an invalid structured decision.',
                'recoverable' => false,
                'fix_applied' => false,
                'changed_files' => [],
                'fix_summary' => null,
                'escalation_reason' => 'The recovery decision failed structural validation and could not be trusted.',
            ];
        }

        return [
            'category' => $validated['root_cause_category'],
            'summary' => $validated['root_cause_summary'],
            'recoverable' => $validated['recoverable'],
            'fix_applied' => $validated['fix_applied'],
            'changed_files' => $validated['changed_files'] ?? [],
            'fix_summary' => $validated['fix_summary'] ?? null,
            'escalation_reason' => $validated['escalation_reason'] ?? null,
            'base_sha' => $preflight['head_sha'],
        ];
    }

    /**
     * Materializes the Recovery Engineer's edits from the disposable worktree into the live
     * repository's working tree as plain, uncommitted file changes, mirroring exactly what the
     * harness would have produced had it edited the live checkout directly. AIOS still performs
     * every trust decision afterward: RecoveryRepositoryLifecycle::validate()/commit() (used by
     * applyRepair()) independently re-diffs the live repository against the recorded base SHA and
     * refuses to commit unless that diff exactly matches the declared changed files, so a path this
     * method skips (traversal, symlink escape, or a file the worktree never actually produced)
     * simply fails that later match rather than silently entering durable state.
     *
     * @param  array<int, mixed>  $changedFiles  untrusted, not-yet-validated decision JSON
     */
    private function copyChangedFilesIntoRepository(string $worktreePath, string $repositoryPath, array $changedFiles): void
    {
        $worktreeRoot = realpath($worktreePath);
        $repositoryRoot = realpath($repositoryPath);

        if ($worktreeRoot === false || $repositoryRoot === false) {
            return;
        }

        foreach ($changedFiles as $relative) {
            if (! is_string($relative) || $relative === '' || Str::contains($relative, ["\0", '..']) || Str::startsWith($relative, ['/', '\\'])) {
                continue;
            }

            $source = $worktreeRoot.DIRECTORY_SEPARATOR.$relative;
            $destination = $repositoryRoot.DIRECTORY_SEPARATOR.$relative;
            $resolvedSource = realpath($source);

            if ($resolvedSource === false) {
                // The agent deleted this file in the worktree; mirror the deletion in the live repo.
                if (is_file($destination)) {
                    @unlink($destination);
                }

                continue;
            }

            if (! Str::startsWith($resolvedSource, $worktreeRoot.DIRECTORY_SEPARATOR) || ! is_file($resolvedSource)) {
                continue;
            }

            File::ensureDirectoryExists(dirname($destination));
            @copy($resolvedSource, $destination);
        }
    }

    /** @param array{category: string, summary: string, recoverable: bool, fix_applied: bool, changed_files: array<int, string>, fix_summary: ?string, escalation_reason: ?string, base_sha?: string} $classification */
    private function applyRepair(RecoveryIncident $incident, array $classification, ?Task $task): RecoveryIncident
    {
        $repositoryPath = (string) config('aios.recovery_repository_path');
        $baseSha = $classification['base_sha'] ?? null;
        $validation = $baseSha === null ? ['passed' => false, 'checks' => ['base_sha' => false], 'evidence' => []] : $this->lifecycle->validate($repositoryPath, $classification['changed_files']);

        if (! $validation['passed']) {
            $incident->update(['status' => RecoveryIncidentStatus::Validating]);

            return $this->escalate($incident, $classification, $task, 'The proposed fix failed AIOS-independent validation.', $validation);
        }

        $incident->update(['status' => RecoveryIncidentStatus::Repairing]);
        $commitSha = $this->lifecycle->commit($repositoryPath, $classification['changed_files'], (string) $baseSha, "recovery: incident #{$incident->id} ({$incident->failure_type})");

        if ($commitSha === null) {
            return $this->escalate($incident, $classification, $task, 'The validated fix could not be committed to the AIOS recovery repository.', $validation);
        }

        $incident->update([
            'status' => RecoveryIncidentStatus::Validating,
            'base_sha' => $baseSha,
            'head_sha' => $commitSha,
            'commit_sha' => $commitSha,
            'changed_files' => $classification['changed_files'],
            'validation_evidence' => $validation,
            'fix_summary' => $classification['fix_summary'],
        ]);
        $this->audit->record('recovery.fix_committed', [
            'recovery_incident_id' => $incident->id,
            'base_sha' => $baseSha,
            'commit_sha' => $commitSha,
            'changed_files' => $classification['changed_files'],
        ], $incident->project, $task);

        return $this->recover($incident, $task, $classification['fix_summary'] ?? $classification['summary']);
    }

    private function recover(RecoveryIncident $incident, ?Task $task, ?string $fixSummary): RecoveryIncident
    {
        $incident->update(['status' => RecoveryIncidentStatus::Validating]);
        $resultingTransition = $task === null ? null : $this->requeueTask($task);

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

    /** A fresh normal Coder/Reviewer attempt is claimed independently by the durable workflow loop. */
    private function requeueTask(Task $task): string
    {
        $status = TaskStatus::from($task->getRawOriginal('status'));

        return match ($status) {
            TaskStatus::Blocked => $this->transitionAndReturn($task, TaskStatus::Queued),
            TaskStatus::Interrupted => $this->transitionAndReturn($task, TaskStatus::Failed),
            // A Failed task is already selected by the normal Coder claim query; no transition is needed.
            default => $status->value,
        };
    }

    private function transitionAndReturn(Task $task, TaskStatus $to): string
    {
        $this->workflow->transition($task, $to);

        return $to->value;
    }

    /**
     * @param  array{category: string, summary: string, recoverable: bool, fix_applied: bool, changed_files: array<int, string>, fix_summary: ?string, escalation_reason: ?string}  $classification
     * @param  ?array<string, mixed>  $validationEvidence
     */
    private function escalate(RecoveryIncident $incident, array $classification, ?Task $task, ?string $reasonOverride = null, ?array $validationEvidence = null): RecoveryIncident
    {
        $incident->update([
            'status' => RecoveryIncidentStatus::Escalated,
            'recoverable' => false,
            'escalation_reason' => $reasonOverride ?? $classification['escalation_reason'] ?? 'Automatic recovery could not safely resolve this incident.',
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

    private function buildPrompt(RecoveryIncident $incident, ?Task $task, string $baseSha): string
    {
        $previousAttempts = $incident->recoveryRuns()->latest('started_at')->limit(5)->get()
            ->map(fn (AgentRun $run): array => ['status' => $run->getRawOriginal('status'), 'exit_code' => $run->exit_code, 'decision' => $run->result])
            ->all();

        $context = [
            'objective' => 'Diagnose the root cause of an AIOS workflow failure and, only if it is a bounded AIOS/system defect you can safely fix, apply the smallest production-safe fix inside this repository (AIOS itself, not the managed project).',
            'repository_base_sha' => $baseSha,
            'incident' => $incident->only(['id', 'failure_type', 'status', 'attempt_count', 'evidence']),
            'task' => $task?->only(['key', 'title', 'objective', 'acceptance_criteria', 'status']),
            'previous_recovery_attempts' => $previousAttempts,
            'root_cause_categories' => ['application_defect', 'orchestration_defect', 'configuration_environment', 'transient_harness_failure', 'stale_lease', 'validation_failure', 'unsafe_git_state', 'managed_project_defect'],
            'response_contract' => 'Return exactly one JSON object with: root_cause_category (one of root_cause_categories), root_cause_summary (string), recoverable (bool), fix_applied (bool, true only if you edited files in this repository), changed_files (array of relative paths you edited, required when fix_applied is true), fix_summary (string, required when fix_applied is true), escalation_reason (string, required when recoverable is false).',
            'constraints' => 'Only edit files in this repository (AIOS itself) if the root cause is an AIOS orchestration/application defect you are confident about and can fix with a minimal, safe change. Never edit the managed project. Never run git add/commit/reset/stash/checkout yourself; AIOS validates and commits independently. If the root cause is the managed project\'s own implementation, an environment/credential problem, or ambiguous, set recoverable/fix_applied accordingly instead of guessing.',
        ];

        $prompt = "You are the AIOS Workflow Recovery Engineer, a system-level reliability role distinct from Project Manager/Coder/Reviewer. Read AGENTS.md and MASTER-PROMPT.md in this repository first.\n\n".json_encode($context, JSON_THROW_ON_ERROR);

        return $prompt;
    }
}
