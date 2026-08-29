<?php

namespace App\Actions;

use App\AgentRole;
use App\AgentRunStatus;
use App\Exceptions\AgentNotBoundToRole;
use App\Exceptions\UnsafeProjectPath;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Services\AgentContextAssembler;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResolver;
use App\Services\AgentResolver;
use App\Services\AgentRunRecorder;
use App\Services\AssembledAgentContext;
use App\Services\AuditLogger;
use App\Services\CoderRepositoryGuard;
use App\Services\CodexCliRunner;
use App\Services\DatabaseProtectionGuard;
use App\Services\ExecutionBudgetPolicy;
use App\Services\ManagedValidationProcessCleanup;
use App\Services\NoProgressRetryGuard;
use App\Services\ProjectGitState;
use App\Services\StaleWorkerRecovery;
use App\Services\TaskCommitter;
use App\Services\TaskContextCapsuleFactory;
use App\Services\TaskContractGuard;
use App\Services\TaskGitIntegrator;
use App\Services\TaskPlanningDefectPreflight;
use App\Services\TaskPlanningEscalationWorkflow;
use App\Services\TaskWorkflow;
use App\Services\TaskWorktreeManager;
use App\Services\WorkerHeartbeat;
use App\Services\WorkflowBoundaryHandoffRecorder;
use App\Services\WorkspacePathResolver;
use App\TaskStatus;
use App\WorkerLease;
use LogicException;
use Throwable;

class RunCoderTask
{
    /**
     * Inject the existing Coder execution, validation, Git, workflow, handoff, and Task-worktree boundaries.
     */
    public function __construct(
        private CodexCliRunner $runner,
        private AgentResolver $agents,
        private AgentHarnessResolver $harnesses,
        private AgentContextAssembler $contextAssembler,
        private AgentRunRecorder $runs,
        private TaskContextCapsuleFactory $capsules,
        private TaskContractGuard $contracts,
        private TaskPlanningDefectPreflight $planningPreflight,
        private TaskPlanningEscalationWorkflow $planningEscalations,
        private ManagedValidationProcessCleanup $validationProcessCleanup,
        private TaskCommitter $committer,
        private TaskWorkflow $workflow,
        private NoProgressRetryGuard $noProgress,
        private WorkerHeartbeat $heartbeat,
        private AuditLogger $audit,
        private WorkspacePathResolver $paths,
        private CoderRepositoryGuard $repositoryGuard,
        private ProjectGitState $git,
        private DatabaseProtectionGuard $databaseProtection,
        private ExecutionBudgetPolicy $executionBudget,
        private WorkflowBoundaryHandoffRecorder $boundaryHandoffs,
        private StaleWorkerRecovery $staleRecovery,
        private TaskWorktreeManager $worktrees,
        private TaskGitIntegrator $gitIntegration,
    ) {}

    /**
     * Execute one claimed Coder task through an AIOS-owned attempt workspace and serialized repository integration.
     */
    public function handle(Task $task, ?WorkerLease $lease = null): TaskAttempt
    {
        abort_unless(in_array(TaskStatus::from($task->getRawOriginal('status')), [TaskStatus::Coding, TaskStatus::Validating], true), 409, 'Only claimed coding tasks may execute.');
        $task->loadMissing('project');

        try {
            $projectPath = $this->paths->assertProjectPath($task->project->path);
        } catch (UnsafeProjectPath $exception) {
            return $this->blockUnsafeProjectPath($task, $exception);
        }

        $recoveredFinalization = $this->recoverDurableGitFinalization($task, $lease);

        if ($recoveredFinalization !== null) {
            return $recoveredFinalization;
        }

        if ($this->staleRecovery->recoverAbandonedCoderFinalization($task)) {
            $interruptedAttempt = $task->attempts()
                ->where('status', 'interrupted')
                ->latest('number')
                ->firstOrFail();

            try {
                $this->worktrees->release($task, $interruptedAttempt);
            } catch (Throwable $throwable) {
                report($throwable);
            }

            return $interruptedAttempt;
        }

        $activeAttempt = $this->activeCoderAttempt($task);

        if ($activeAttempt !== null) {
            return $activeAttempt;
        }

        // This is intentionally before repository/harness work: unsafe PM-authored contract
        // metadata is a planning defect, never a failed implementation attempt.
        $planningDefect = $this->planningPreflight->evaluate($task);
        if ($planningDefect !== null) {
            $escalation = $this->planningEscalations->escalate($task, $planningDefect);

            return $escalation->sourceAttempt()->firstOrFail();
        }

        try {
            $preflight = $this->repositoryGuard->inspect($task);

            if (! $preflight['allowed'] || $preflight['base_sha'] === null) {
                return $this->blockRepositoryPreflight($task, $preflight);
            }

            $baseSha = $preflight['base_sha'];
            $context = $this->capsules->make($task);
            $contract = $this->contracts->evaluate($task, $context, $preflight['recovery_attempt']);
        } catch (Throwable $throwable) {
            // Preflight/capsule/contract evaluation runs inside the persistent aios:work loop
            // before any harness call. Fail durably instead of allowing one setup exception to
            // terminate the worker process for every project.
            return $this->blockUnexpectedFailure($task, $throwable);
        }

        if ($contract['drifted']) {
            return $this->blockContractDrift($task, $baseSha, $contract);
        }

        $contractEvidence = $contract['recovery_pinned'] && $contract['baseline'] !== null
            ? $contract['baseline']
            : $contract['current'];

        try {
            [$agent, $harness, $assembled] = $this->executionConfiguration($task, $context, $preflight['recovery_attempt']);
        } catch (LogicException $exception) {
            return $this->blockMisconfiguredAgent($task, $exception);
        }

        $executionSettings = $assembled?->executionSettings ?? $this->executionBudget->forCoderTask($task);
        $assembled = $assembled?->withExecutionSettings($executionSettings);

        $recoveryInstruction = $preflight['mode'] === 'recovery'
            ? 'AIOS has verified that the current working-tree changes are task-owned recovery state tied to the supplied prior attempt. Inspect and continue from them; do not stop solely because the working tree is dirty. Do not stage or commit; AIOS independently validates and commits only verified task files. '
            : '';
        $prompt = "You are the Coder role. Work only on this task. Read AGENTS.md and the task's relevant documentation first. Start with the supplied relevant paths and verification commands; do not scan unrelated areas, run broad test suites, or refactor speculatively unless the task evidence requires it. The roadmap constraints in the context capsule are authoritative; do not substitute another stack or add technology outside that scope. Do not run git add, git commit, git reset, git stash, git checkout, git switch, git merge, git rebase, git cherry-pick, git clean, git worktree, or any other Git mutation. AIOS independently validates and commits only verified task files. {$recoveryInstruction}Return a concise JSON summary.\n\n".json_encode($assembled?->toArray() ?? $context, JSON_THROW_ON_ERROR);
        $attempt = TaskAttempt::create([
            'task_id' => $task->id,
            'number' => $task->attempts()->max('number') + 1,
            'base_sha' => $baseSha,
            'status' => 'running',
            'validation_results' => [
                'repository_preflight' => [
                    'mode' => $preflight['mode'],
                    'base_sha' => $baseSha,
                    'recovery_attempt_number' => $preflight['recovery_attempt']?->number,
                ],
                'task_contract' => $contractEvidence,
            ],
            'started_at' => now(),
        ]);
        $run = $this->runs->start($task->project, AgentRole::Coder, $prompt, $task, $attempt, $lease, $context['retrieval_manifest'], $agent, $assembled);
        $worktreePath = null;

        try {
            $this->databaseProtection->guard($task->project);

            // Preserve the pre-P10 dirty-recovery contract only for already-existing task-owned
            // recovery state. Every normal P10 attempt executes in its own AIOS-selected worktree.
            $legacyRecovery = $preflight['mode'] === 'recovery';
            $executionPath = $legacyRecovery
                ? $projectPath
                : $worktreePath = $this->worktrees->acquire($task, $attempt);

            $onOutput = function (string $type, string $output) use ($run, $task, $lease): void {
                $this->runs->appendLiveOutput($run, $type, $output);
                if ($lease === null) {
                    $this->heartbeat->beat($task->project, AgentRole::Coder);
                }
            };
            $onHeartbeat = $lease === null ? null : fn (): bool => $this->heartbeat->renew($lease);
            $execution = $harness !== null && $agent !== null
                ? $harness->execute($task->project, $agent, $prompt, $onOutput, $onHeartbeat, $executionSettings, $executionPath)->toArray()
                : $this->runner->runAtPath($executionPath, $prompt, $onOutput, $onHeartbeat, executionSettings: $executionSettings);
            $this->runs->complete($run, $execution);

            if ($execution['exit_code'] === 0) {
                $terminatedProcesses = $this->validationProcessCleanup->terminateStaleProcesses($task);
                if ($terminatedProcesses !== []) {
                    $this->audit->record('task.validation_processes_cleaned', [
                        'process_ids' => $terminatedProcesses,
                    ], $task->project, $task);
                }
            }

            $renewLease = $lease === null ? null : fn (): bool => $this->heartbeat->renew($lease);
            if ($renewLease !== null) {
                $renewLease();
            }

            if ($execution['exit_code'] === 0) {
                $task->operatorMessages()->where('recipient_role', AgentRole::Coder)->whereNull('delivered_at')->update(['delivered_at' => now()]);
            }

            $reportedPlanningDefect = $this->reportedPlanningDefect($execution['output']);
            if ($reportedPlanningDefect !== null) {
                $this->planningEscalations->escalate($task, $reportedPlanningDefect, $attempt);

                return $attempt->refresh();
            }

            if (
                $execution['exit_code'] === 0
                && TaskStatus::from($task->getRawOriginal('status')) === TaskStatus::Coding
            ) {
                $task = $this->workflow->transition($task, TaskStatus::Validating);
            }

            if ($execution['exit_code'] !== 0) {
                return $this->failExecutionAttempt(
                    $task,
                    $attempt,
                    $baseSha,
                    $executionPath,
                    $preflight,
                    $contractEvidence,
                    (int) $execution['exit_code'],
                );
            }

            if ($legacyRecovery) {
                return $this->finalizeLegacyRecovery(
                    $task,
                    $attempt,
                    $run,
                    $baseSha,
                    $projectPath,
                    $preflight,
                    $contractEvidence,
                    $renewLease,
                );
            }

            $candidate = $this->gitIntegration->createCandidate($task, $attempt, $executionPath);
            if ($renewLease !== null) {
                $renewLease();
            }
            $integration = $this->gitIntegration->integrate($task, $attempt, $candidate);
            if ($renewLease !== null) {
                $renewLease();
            }

            return $this->finalizeIntegratedAttempt(
                $task,
                $attempt,
                $run,
                $candidate,
                $integration,
                $preflight,
                $contractEvidence,
            );
        } catch (Throwable $throwable) {
            report($throwable);
            $freshRun = $run->fresh();

            if (AgentRunStatus::from($freshRun->getRawOriginal('status')) === AgentRunStatus::Running) {
                $this->runs->complete($freshRun, [
                    'exit_code' => -1,
                    'output' => '',
                    'error_output' => $throwable->getMessage(),
                ]);
            }

            $evidencePath = $worktreePath ?? $projectPath;
            $changedFiles = $this->git->changedFilesFromBase($evidencePath, $baseSha) ?? [];
            $state = $this->git->inspect($projectPath);
            $attempt->update([
                'head_sha' => $state['head_sha'],
                'status' => 'failed',
                'validation_results' => [
                    'passed' => false,
                    'checks' => ['execution_exception' => false],
                    'evidence' => [
                        'execution_exception' => [
                            'name' => 'execution_exception',
                            'passed' => false,
                            'verification_identifier' => 'coder_finalization',
                            'exit_code' => -1,
                            'summary' => $throwable->getMessage(),
                        ],
                    ],
                    'base_sha' => $baseSha,
                    'candidate_changed_files' => $changedFiles,
                    'repository_preflight' => [
                        'mode' => $preflight['mode'],
                        'recovery_attempt_number' => $preflight['recovery_attempt']?->number,
                    ],
                    'task_contract' => $contractEvidence,
                ],
                'changed_files' => $changedFiles,
                'finished_at' => now(),
            ]);
            $this->audit->record('task.validated', [
                'attempt_number' => $attempt->number,
                'passed' => false,
                'checks' => ['execution_exception' => false],
                'base_sha' => $baseSha,
                'changed_files' => $changedFiles,
            ], $task->project, $task);
            $this->workflow->transition($task, $this->retryStatus($task, $attempt));

            return $attempt->refresh();
        } finally {
            if ($worktreePath !== null) {
                try {
                    $this->worktrees->release($task, $attempt);
                } catch (Throwable $throwable) {
                    report($throwable);
                }
            }
        }
    }

    /**
     * Reconcile a durable candidate left by an interrupted post-harness finalization before stale recovery creates a new attempt.
     */
    private function recoverDurableGitFinalization(Task $task, ?WorkerLease $lease): ?TaskAttempt
    {
        $attempt = $task->attempts()
            ->whereIn('status', ['running', 'failed'])
            ->whereNotNull('base_sha')
            ->latest('number')
            ->first();

        if ($attempt === null) {
            return null;
        }

        $validation = $attempt->getAttribute('validation_results');
        $validation = is_array($validation) ? $validation : [];
        $integrationStatus = is_array($validation['git_integration'] ?? null)
            ? $validation['git_integration']['status'] ?? null
            : null;

        if (
            $attempt->getAttribute('status') === 'failed'
            && $integrationStatus !== null
            && ! in_array($integrationStatus, ['lock_timeout', 'integration_uncertain'], true)
        ) {
            return null;
        }

        $run = AgentRun::query()
            ->whereBelongsTo($task)
            ->where('role', AgentRole::Coder)
            ->where('attempt_number', $attempt->number)
            ->where('status', AgentRunStatus::Completed)
            ->where('exit_code', 0)
            ->latest('id')
            ->first();

        if ($run === null) {
            return null;
        }

        try {
            $candidate = $this->gitIntegration->recoverCandidate($task, $attempt);

            if ($candidate === null) {
                return null;
            }

            if ($lease !== null && ! $this->heartbeat->renew($lease)) {
                return null;
            }

            if (TaskStatus::from($task->getRawOriginal('status')) === TaskStatus::Coding) {
                $task = $this->workflow->transition($task, TaskStatus::Validating);
            }

            $integration = $this->gitIntegration->integrate($task, $attempt, $candidate);
            $preflight = is_array($validation['repository_preflight'] ?? null)
                ? $validation['repository_preflight']
                : ['mode' => 'normal', 'recovery_attempt_number' => null];
            $contractEvidence = is_array($validation['task_contract'] ?? null)
                ? $validation['task_contract']
                : [];

            $result = $this->finalizeIntegratedAttempt(
                $task,
                $attempt,
                $run,
                $candidate,
                $integration,
                [
                    'mode' => $preflight['mode'] ?? 'normal',
                    'recovery_attempt' => null,
                ],
                $contractEvidence,
                recovered: true,
            );

            $this->worktrees->release($task, $attempt);

            return $result;
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }
    }

    /**
     * Finalize the compatibility-only pre-P10 dirty-recovery path without changing its existing task-only commit contract.
     *
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $contractEvidence
     * @param  (\Closure(): mixed)|null  $renewLease
     */
    private function finalizeLegacyRecovery(
        Task $task,
        TaskAttempt $attempt,
        AgentRun $run,
        string $baseSha,
        string $projectPath,
        array $preflight,
        array $contractEvidence,
        ?\Closure $renewLease,
    ): TaskAttempt {
        $changedFiles = $this->git->changedFilesFromBase($projectPath, $baseSha);
        $headUnchanged = $this->git->baseMatchesCurrentHead($projectPath, $baseSha);
        $validationPassed = $changedFiles !== null && $headUnchanged;
        $alreadyImplemented = $validationPassed && $changedFiles === [];
        $commitSha = $validationPassed && ! $alreadyImplemented
            ? $this->committer->commit($task, $changedFiles, $baseSha)
            : null;
        $passed = $alreadyImplemented || ($validationPassed && $commitSha !== null);
        $state = $this->git->inspect($projectPath);

        $validation = [
            'passed' => $passed,
            'checks' => [
                'git_change_set' => $changedFiles !== null,
                'git_head_unchanged' => $headUnchanged,
                'task_commit' => $passed,
            ],
            'evidence' => [
                'git_change_set' => [
                    'name' => 'git_change_set',
                    'passed' => $changedFiles !== null,
                    'verification_identifier' => 'git diff --name-only',
                    'exit_code' => $changedFiles === null ? 1 : 0,
                    'files' => $changedFiles ?? [],
                    'summary' => $changedFiles === null ? 'The changed-file set could not be determined from the recovery attempt base.' : null,
                ],
                'task_commit' => [
                    'name' => 'task_commit',
                    'passed' => $passed,
                    'verification_identifier' => 'legacy AIOS task-only commit',
                    'exit_code' => $passed ? 0 : 1,
                    'summary' => $passed ? null : 'The verified legacy recovery state could not be committed safely.',
                ],
            ],
            'base_sha' => $baseSha,
            'candidate_changed_files' => $changedFiles ?? [],
            'repository_preflight' => [
                'mode' => $preflight['mode'],
                'recovery_attempt_number' => $preflight['recovery_attempt']?->number,
            ],
            'task_contract' => $contractEvidence,
            'managed_processes' => [],
        ];

        if ($passed) {
            $this->syncProjectGitState($task, $state);
        }

        $attempt->update([
            'head_sha' => $state['head_sha'],
            'commit_sha' => $commitSha,
            'status' => $passed ? 'completed' : 'failed',
            'validation_results' => $validation,
            'changed_files' => $changedFiles ?? [],
            'finished_at' => now(),
        ]);

        if ($renewLease !== null) {
            $renewLease();
        }

        $this->recordValidationAudit($task, $attempt, $validation, $commitSha, $baseSha, $changedFiles ?? []);
        $transitionedTask = $this->workflow->transition(
            $task,
            $passed ? TaskStatus::ReadyForReview : $this->retryStatus($task, $attempt),
        );

        if ($passed) {
            $this->boundaryHandoffs->recordImplementationReady(
                $transitionedTask,
                $attempt->refresh(),
                $run->refresh(),
            );
        }

        return $attempt->refresh();
    }

    /**
     * Persist one candidate/integration outcome and cross the review boundary only after verified canonical success.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $integration
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $contractEvidence
     */
    private function finalizeIntegratedAttempt(
        Task $task,
        TaskAttempt $attempt,
        AgentRun $run,
        array $candidate,
        array $integration,
        array $preflight,
        array $contractEvidence,
        bool $recovered = false,
    ): TaskAttempt {
        $passed = ($integration['passed'] ?? false) === true;
        $changedFiles = is_array($candidate['changed_files'] ?? null) ? $candidate['changed_files'] : [];
        $headAfter = is_string($integration['canonical_head_after'] ?? null)
            ? $integration['canonical_head_after']
            : null;
        $commitSha = is_string($integration['integrated_sha'] ?? null)
            ? $integration['integrated_sha']
            : null;
        $candidateEvidence = [
            'name' => 'git_candidate',
            'passed' => true,
            'verification_identifier' => 'AIOS Task candidate commit/ref',
            'exit_code' => 0,
            'base_sha' => $candidate['base_sha'],
            'candidate_sha' => $candidate['candidate_sha'],
            'candidate_ref' => $candidate['candidate_ref'],
            'candidate_diff_sha256' => $candidate['candidate_diff_sha256'],
            'files' => $changedFiles,
            'summary' => ($candidate['no_changes'] ?? false) === true
                ? 'The isolated Task worktree produced no candidate changes.'
                : 'AIOS created and verified a durable Task-only candidate commit.',
        ];
        $integrationEvidence = [
            'name' => 'git_integration',
            'passed' => $passed,
            'verification_identifier' => 'AIOS serialized canonical Git integration',
            'exit_code' => $passed ? 0 : 1,
            'status' => $integration['status'] ?? 'unknown',
            'base_sha' => $integration['base_sha'] ?? $attempt->base_sha,
            'candidate_sha' => $integration['candidate_sha'] ?? null,
            'candidate_ref' => $integration['candidate_ref'] ?? null,
            'candidate_diff_sha256' => $integration['candidate_diff_sha256'] ?? null,
            'canonical_head_before' => $integration['canonical_head_before'] ?? null,
            'canonical_head_after' => $headAfter,
            'integrated_sha' => $commitSha,
            'conflict_paths' => is_array($integration['conflict_paths'] ?? null) ? $integration['conflict_paths'] : [],
            'files' => $changedFiles,
            'summary' => (string) ($integration['summary'] ?? 'AIOS could not verify canonical integration.'),
        ];
        $validation = $attempt->getAttribute('validation_results');
        $validation = is_array($validation) ? $validation : [];
        $validation = [
            ...$validation,
            'passed' => $passed,
            'checks' => [
                ...((is_array($validation['checks'] ?? null)) ? $validation['checks'] : []),
                'git_candidate' => true,
                'git_integration' => $passed,
            ],
            'evidence' => [
                ...((is_array($validation['evidence'] ?? null)) ? $validation['evidence'] : []),
                'git_candidate' => $candidateEvidence,
                'git_integration' => $integrationEvidence,
            ],
            'base_sha' => $attempt->base_sha,
            'candidate_changed_files' => $changedFiles,
            'git_integration' => $integration,
            'repository_preflight' => [
                'mode' => $preflight['mode'] ?? 'normal',
                'recovery_attempt_number' => isset($preflight['recovery_attempt'])
                    && $preflight['recovery_attempt'] instanceof TaskAttempt
                    ? $preflight['recovery_attempt']->number
                    : ($preflight['recovery_attempt_number'] ?? null),
            ],
            'task_contract' => $contractEvidence,
            'managed_processes' => [],
        ];

        if ($passed) {
            $state = $this->git->inspect((string) $task->project->path);

            if (
                ! $state['inspectable']
                || ! $state['clean']
                || ! is_string($state['head_sha'])
                || ! is_string($headAfter)
                || ! hash_equals($headAfter, $state['head_sha'])
            ) {
                $passed = false;
                $validation['passed'] = false;
                $validation['checks']['git_integration'] = false;
                $validation['evidence']['git_integration']['passed'] = false;
                $validation['evidence']['git_integration']['exit_code'] = 1;
                $validation['evidence']['git_integration']['summary'] = 'Canonical integration returned success but AIOS could not independently verify the final clean HEAD.';
            } else {
                $this->syncProjectGitState($task, $state);
            }
        }

        $attempt->update([
            'head_sha' => $headAfter,
            'commit_sha' => $passed ? $commitSha : null,
            'status' => $passed ? 'completed' : 'failed',
            'validation_results' => $validation,
            'changed_files' => $changedFiles,
            'finished_at' => now(),
        ]);
        $this->recordValidationAudit(
            $task,
            $attempt,
            $validation,
            $passed ? $commitSha : null,
            (string) $attempt->base_sha,
            $changedFiles,
        );

        if ($recovered) {
            $this->audit->record('task.git_integration_recovered', [
                'attempt_number' => $attempt->number,
                'candidate_sha' => $candidate['candidate_sha'],
                'integration_status' => $integration['status'] ?? null,
                'integrated_sha' => $passed ? $commitSha : null,
            ], $task->project, $task);
        }

        $transitionedTask = $this->workflow->transition(
            $task,
            $passed ? TaskStatus::ReadyForReview : $this->retryStatus($task, $attempt),
        );

        if ($passed) {
            $this->boundaryHandoffs->recordImplementationReady(
                $transitionedTask,
                $attempt->refresh(),
                $run->refresh(),
            );
        }

        return $attempt->refresh();
    }

    /**
     * Persist one non-zero Coder execution as a failed attempt without integrating its disposable workspace state.
     *
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $contractEvidence
     */
    private function failExecutionAttempt(
        Task $task,
        TaskAttempt $attempt,
        string $baseSha,
        string $executionPath,
        array $preflight,
        array $contractEvidence,
        int $exitCode,
    ): TaskAttempt {
        $changedFiles = $this->git->changedFilesFromBase($executionPath, $baseSha) ?? [];
        $state = $this->git->inspect((string) $task->project->path);
        $validation = [
            'passed' => false,
            'checks' => ['coder_execution' => false],
            'evidence' => [
                'coder_execution' => [
                    'name' => 'coder_execution',
                    'passed' => false,
                    'verification_identifier' => 'Coder harness exit code',
                    'exit_code' => $exitCode,
                    'summary' => 'The Coder harness did not complete successfully, so AIOS did not create or integrate a Task candidate.',
                ],
            ],
            'base_sha' => $baseSha,
            'candidate_changed_files' => $changedFiles,
            'repository_preflight' => [
                'mode' => $preflight['mode'],
                'recovery_attempt_number' => $preflight['recovery_attempt']?->number,
            ],
            'task_contract' => $contractEvidence,
            'managed_processes' => [],
        ];

        $attempt->update([
            'head_sha' => $state['head_sha'],
            'status' => 'failed',
            'validation_results' => $validation,
            'changed_files' => $changedFiles,
            'finished_at' => now(),
        ]);
        $this->recordValidationAudit($task, $attempt, $validation, null, $baseSha, $changedFiles);
        $this->workflow->transition($task, $this->retryStatus($task, $attempt));

        return $attempt->refresh();
    }

    /**
     * Record the existing task.validated audit contract with candidate and integration evidence already persisted on the attempt.
     *
     * @param  array<string, mixed>  $validation
     * @param  list<string>  $changedFiles
     */
    private function recordValidationAudit(
        Task $task,
        TaskAttempt $attempt,
        array $validation,
        ?string $commitSha,
        string $baseSha,
        array $changedFiles,
    ): void {
        $this->audit->record('task.validated', [
            'attempt_number' => $attempt->number,
            'passed' => (bool) ($validation['passed'] ?? false),
            'checks' => is_array($validation['checks'] ?? null) ? $validation['checks'] : [],
            'commit_sha' => $commitSha,
            'base_sha' => $baseSha,
            'changed_files' => $changedFiles,
            'git_integration' => is_array($validation['git_integration'] ?? null) ? $validation['git_integration'] : null,
        ], $task->project, $task);
    }

    /**
     * Do not permit a replacement Coder attempt while its predecessor is still executing.
     *
     * A lease can expire while its host process is paused; launching a new attempt in the
     * same repository before the original AgentRun has finished creates competing writers.
     */
    private function activeCoderAttempt(Task $task): ?TaskAttempt
    {
        $run = AgentRun::query()
            ->whereBelongsTo($task)
            ->where('role', AgentRole::Coder)
            ->where('status', AgentRunStatus::Running)
            ->latest('id')
            ->first();

        if ($run === null) {
            return null;
        }

        if ($run->attempt_number !== null) {
            return $task->attempts()
                ->where('number', $run->attempt_number)
                ->first();
        }

        return $task->attempts()
            ->where('status', 'running')
            ->latest('number')
            ->first();
    }

    /**
     * Resolve the current or interrupted-attempt Agent execution configuration deterministically.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: ?Agent, 1: ?AgentHarness, 2: ?AssembledAgentContext}
     */
    private function executionConfiguration(Task $task, array $context, ?TaskAttempt $recoveryAttempt): array
    {
        if ($recoveryAttempt === null) {
            [$agent, $harness] = $this->resolveAgent($task->project, AgentRole::Coder);

            return [$agent, $harness, $agent === null ? null : $this->contextAssembler->assemble($agent, AgentRole::Coder, $context)];
        }

        $run = AgentRun::query()
            ->whereBelongsTo($task)
            ->where('role', AgentRole::Coder)
            ->where('attempt_number', $recoveryAttempt->number)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        if ($run === null) {
            return [null, null, null];
        }

        $snapshot = $run->getAttribute('configuration_snapshot');

        if ($run->agent_id === null && $snapshot === null) {
            return [null, null, null];
        }

        if (! is_array($snapshot)) {
            throw new LogicException('The interrupted Coder run is missing its immutable configuration snapshot; explicitly requeue/rebase the task instead of adopting current Agent configuration.');
        }

        $agent = $this->contextAssembler->agentFromSnapshot($snapshot, $task->project->id);

        if (! Agent::query()->whereKey($agent->id)->where('project_id', $task->project->id)->exists()) {
            throw new LogicException('The Agent referenced by the interrupted Coder configuration snapshot no longer exists in this project.');
        }

        return [
            $agent,
            $this->harnesses->resolve($agent),
            $this->contextAssembler->restore($snapshot, $context),
        ];
    }

    /**
     * Persist the latest inspected managed-project Git state on the project record.
     *
     * @param array{
     *     inspectable: bool,
     *     clean: bool,
     *     head_sha: ?string,
     *     base_sha: ?string,
     *     staged_files: array<int, string>,
     *     unstaged_files: array<int, string>,
     *     untracked_files: array<int, string>,
     *     errors: array<int, string>
     * } $state
     */
    private function syncProjectGitState(Task $task, array $state): void
    {
        $task->project->update([
            'git_head_sha' => $state['head_sha'],
            'git_status' => $state['inspectable'] ? ($state['clean'] ? 'clean' : 'dirty') : 'unknown',
        ]);
    }

    /**
     * Resolve the bounded Coder retry status while preserving no-progress evidence.
     */
    private function retryStatus(Task $task, TaskAttempt $attempt): TaskStatus
    {
        $attempt = $attempt->refresh();
        $noProgress = $this->noProgress->coderFailure($task, $attempt);
        $validationResults = json_decode((string) $attempt->getRawOriginal('validation_results'), true);
        $validationResults = is_array($validationResults) ? $validationResults : [];
        $attempt->update(['validation_results' => [...$validationResults, 'no_progress' => $noProgress]]);

        $limit = max(1, (int) config('aios.max_coder_attempts'));
        $lastRecovery = $task->auditEvents()
            ->whereIn('event_type', ['task.requeued', 'task.coder_retry_exhausted'])
            ->latest('occurred_at')
            ->first();
        $attemptsSinceRecovery = $task->attempts()
            ->when($lastRecovery !== null, fn ($query) => $query->where('created_at', '>=', $lastRecovery->getRawOriginal('occurred_at')))
            ->count();

        if ($attemptsSinceRecovery >= $limit) {
            $this->audit->record('task.coder_retry_exhausted', [
                'attempt_number' => $attempt->number,
                'retry_count' => $attemptsSinceRecovery,
                'retry_limit' => $limit,
                'no_progress' => $noProgress,
            ], $task->project, $task);

            return TaskStatus::Blocked;
        }

        if ($noProgress['detected']) {
            $this->audit->record('task.no_progress_detected', [
                'operation' => 'coder',
                'attempt_number' => $attempt->number,
                'failure_fingerprint' => $noProgress['failure_fingerprint'],
                'consecutive_identical_failures' => $noProgress['consecutive_identical_failures'],
                'consecutive_repeat_count' => $noProgress['consecutive_repeat_count'],
                'threshold' => $noProgress['threshold'],
                'base_sha' => $attempt->base_sha,
                'head_sha' => $attempt->head_sha,
                'changed_files' => $attempt->changed_files ?? [],
                'repository_fingerprint' => $noProgress['repository_fingerprint'],
            ], $task->project, $task);

            return TaskStatus::Blocked;
        }

        return TaskStatus::Failed;
    }

    /**
     * Parse one bounded Coder-reported deterministic planning defect proposal when present.
     *
     * @return array{type:string,fingerprint:string,evidence:array<string,mixed>,allowed_fields:list<string>}|null
     */
    private function reportedPlanningDefect(string $output): ?array
    {
        $decoded = json_decode($output, true);
        $proposal = is_array($decoded) && is_array($decoded['planning_defect'] ?? null) ? $decoded['planning_defect'] : null;
        $field = $proposal['field'] ?? null;
        $type = $proposal['type'] ?? null;
        $evidence = $proposal['evidence'] ?? null;
        if (! is_string($field) || ! is_string($type) || ! is_array($evidence) || ! in_array($field, TaskPlanningDefectPreflight::AllowedFields, true) || ! in_array($type, ['missing_contract_detail', 'unsafe_declared_tooling', 'invalid_dependency_placement'], true)) {
            return null;
        }

        return ['type' => $type, 'fingerprint' => hash('sha256', json_encode([$type, $field, $evidence], JSON_THROW_ON_ERROR)), 'evidence' => ['field' => $field, ...$evidence], 'allowed_fields' => [$field]];
    }

    /**
     * Resolve the project Agent and harness for the requested workflow role.
     *
     * @return array{0: ?Agent, 1: ?AgentHarness}
     */
    private function resolveAgent(Project $project, AgentRole $role): array
    {
        try {
            $agent = $this->agents->forRole($project, $role);
        } catch (AgentNotBoundToRole) {
            return [null, null];
        }

        return [$agent, $this->harnesses->resolve($agent)];
    }

    /**
     * Block deterministic Task contract drift before a Coder harness executes.
     *
     * @param array{
     *     drifted: bool,
     *     baseline: ?array{schema_version: int, fingerprint: string, input_hashes: array<string, mixed>},
     *     current: array{schema_version: int, fingerprint: string, input_hashes: array<string, mixed>},
     *     baseline_attempt_number: ?int,
     *     changed_inputs: list<string>,
     *     recovery_pinned: bool
     * } $contract
     */
    private function blockContractDrift(Task $task, string $baseSha, array $contract): TaskAttempt
    {
        $attempt = TaskAttempt::create([
            'task_id' => $task->id,
            'number' => $task->attempts()->max('number') + 1,
            'base_sha' => $baseSha,
            'status' => 'failed',
            'validation_results' => [
                'passed' => false,
                'checks' => ['task_contract' => false],
                'task_contract' => $contract['current'],
                'contract_drift' => [
                    'baseline_attempt_number' => $contract['baseline_attempt_number'],
                    'baseline_fingerprint' => $contract['baseline']['fingerprint'] ?? null,
                    'current_fingerprint' => $contract['current']['fingerprint'],
                    'changed_inputs' => $contract['changed_inputs'],
                    'recovery_pinned' => $contract['recovery_pinned'],
                ],
            ],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->audit->record('task.contract_drift_detected', [
            'operation' => 'coder',
            'baseline_attempt_number' => $contract['baseline_attempt_number'],
            'blocked_attempt_number' => $attempt->number,
            'baseline_fingerprint' => $contract['baseline']['fingerprint'] ?? null,
            'current_fingerprint' => $contract['current']['fingerprint'],
            'changed_inputs' => $contract['changed_inputs'],
            'recovery_pinned' => $contract['recovery_pinned'],
        ], $task->project, $task);
        $this->planningEscalations->escalate($task, [
            'type' => 'task_contract_drift',
            'fingerprint' => $contract['current']['fingerprint'],
            'evidence' => [
                'baseline_attempt_number' => $contract['baseline_attempt_number'],
                'baseline_fingerprint' => $contract['baseline']['fingerprint'] ?? null,
                'current_fingerprint' => $contract['current']['fingerprint'],
                'changed_inputs' => $contract['changed_inputs'],
                'recovery_pinned' => $contract['recovery_pinned'],
            ],
            'allowed_fields' => [
                'acceptance_criteria',
                'scope',
                'constraints',
                'relevant_paths',
                'verification_commands',
                'implementation_prompt',
                'dependencies',
            ],
        ], $attempt);

        return $attempt->refresh();
    }

    /**
     * Block one Coder task when the bound Agent configuration is invalid.
     */
    private function blockMisconfiguredAgent(Task $task, LogicException $exception): TaskAttempt
    {
        $attempt = TaskAttempt::create([
            'task_id' => $task->id,
            'number' => $task->attempts()->max('number') + 1,
            'status' => 'blocked',
            'validation_results' => [
                'passed' => false,
                'checks' => ['agent_binding' => false],
                'error' => $exception->getMessage(),
                'action' => 'Resolve the bound Coder Agent configuration for this project, then requeue the task.',
            ],
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $this->audit->record('task.blocked_agent_misconfigured', [
            'attempt_number' => $attempt->number,
            'reason' => $exception->getMessage(),
        ], $task->project, $task);
        $this->workflow->transition($task, TaskStatus::Blocked);

        return $attempt;
    }

    /**
     * Block one Coder task whose persisted workspace path violates project isolation.
     */
    private function blockUnsafeProjectPath(Task $task, UnsafeProjectPath $exception): TaskAttempt
    {
        $attempt = TaskAttempt::create([
            'task_id' => $task->id,
            'number' => $task->attempts()->max('number') + 1,
            'status' => 'failed',
            'validation_results' => ['passed' => false, 'checks' => ['workspace_path' => false], 'error' => $exception->getMessage()],
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $this->audit->record('task.blocked_unsafe_path', ['attempt_number' => $attempt->number], $task->project, $task);
        $this->workflow->transition($task, TaskStatus::Blocked);

        return $attempt;
    }

    /**
     * Convert an unexpected pre-execution exception into durable blocked Task evidence.
     */
    private function blockUnexpectedFailure(Task $task, Throwable $throwable): TaskAttempt
    {
        report($throwable);

        $attempt = TaskAttempt::create([
            'task_id' => $task->id,
            'number' => $task->attempts()->max('number') + 1,
            'status' => 'failed',
            'validation_results' => ['passed' => false, 'checks' => ['pre_execution' => false], 'error' => $throwable->getMessage()],
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $this->audit->record('task.blocked_unexpected_error', ['attempt_number' => $attempt->number, 'error' => $throwable->getMessage()], $task->project, $task);
        $this->workflow->transition($task, TaskStatus::Blocked);

        return $attempt;
    }

    /**
     * Block one Coder task when repository preflight cannot prove a safe execution base.
     *
     * @param array{
     *     allowed: bool,
     *     mode: 'normal'|'recovery'|'blocked',
     *     base_sha: ?string,
     *     recovery_attempt: ?TaskAttempt,
     *     state: array{
     *         inspectable: bool,
     *         clean: bool,
     *         head_sha: ?string,
     *         base_sha: ?string,
     *         staged_files: array<int, string>,
     *         unstaged_files: array<int, string>,
     *         untracked_files: array<int, string>,
     *         errors: array<int, string>
     *     }
     * } $preflight
     */
    private function blockRepositoryPreflight(Task $task, array $preflight): TaskAttempt
    {
        $state = $preflight['state'];
        $attempt = TaskAttempt::create([
            'task_id' => $task->id,
            'number' => $task->attempts()->max('number') + 1,
            'base_sha' => $preflight['base_sha'],
            'status' => 'blocked',
            'validation_results' => [
                'passed' => false,
                'checks' => ['repository_preflight' => false],
                'repository_state' => $state,
                'action' => 'Resolve the repository state manually, then requeue the task. AIOS did not stash, reset, clean, discard, or commit these changes.',
            ],
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $task->project->update([
            'git_head_sha' => $state['head_sha'],
            'git_status' => $state['inspectable'] ? ($state['clean'] ? 'clean' : 'dirty') : 'unknown',
        ]);
        $this->audit->record('task.blocked_dirty_repository', [
            'attempt_number' => $attempt->number,
            'head_sha' => $state['head_sha'],
            'base_sha' => $state['base_sha'],
            'staged_files' => $state['staged_files'],
            'unstaged_files' => $state['unstaged_files'],
            'untracked_files' => $state['untracked_files'],
            'errors' => $state['errors'],
            'action' => 'Resolve the repository state manually, then requeue the task. AIOS did not modify the repository.',
        ], $task->project, $task);
        $this->workflow->transition($task, TaskStatus::Blocked);

        return $attempt;
    }
}
