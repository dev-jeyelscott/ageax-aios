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
use App\Services\TaskPlanningDefectPreflight;
use App\Services\TaskPlanningEscalationWorkflow;
use App\Services\TaskWorkflow;
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
     * Inject the existing Coder execution, validation, Git, workflow, and handoff boundaries.
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
    ) {}

    /**
     * Execute one claimed Coder task through AIOS-owned validation, commit, and review readiness.
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

        if ($this->staleRecovery->recoverAbandonedCoderFinalization($task)) {
            return $task->attempts()
                ->where('status', 'interrupted')
                ->latest('number')
                ->firstOrFail();
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
            // (App\Console\Commands\RunAiosWorkers) before any harness call. An uncaught
            // exception here previously escaped this Action entirely and killed the worker
            // process for every project (see the TaskContractGuard regex-delimiter incident).
            // Fail this attempt the same way other pre-execution blocks do instead of throwing.
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
            ? 'AIOS has verified that the current working-tree changes are task-owned recovery state tied to the supplied prior attempt. Inspect and continue from them; do not stop solely because the worktree is dirty. Do not stage or commit; AIOS independently validates and commits only verified task files. '
            : '';
        $prompt = "You are the Coder role. Work only on this task. Read AGENTS.md and the task's relevant documentation first. Start with the supplied relevant paths and verification commands; do not scan unrelated areas, run broad test suites, or refactor speculatively unless the task evidence requires it. The roadmap constraints in the context capsule are authoritative; do not substitute another stack or add technology outside that scope. Do not run git add, git commit, git reset, git stash, git checkout, git switch, git merge, git rebase, git cherry-pick, git clean, or any other Git mutation. AIOS independently validates and commits only verified task files. {$recoveryInstruction}Return a concise JSON summary.\n\n".json_encode($assembled?->toArray() ?? $context, JSON_THROW_ON_ERROR);
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

        try {
            $this->databaseProtection->guard($task->project);
            $onOutput = function (string $type, string $output) use ($run, $task, $lease): void {
                $this->runs->appendLiveOutput($run, $type, $output);
                if ($lease === null) {
                    $this->heartbeat->beat($task->project, AgentRole::Coder);
                }
            };
            $onHeartbeat = $lease === null ? null : fn (): bool => $this->heartbeat->renew($lease);
            $execution = $harness !== null && $agent !== null
                ? $harness->execute($task->project, $agent, $prompt, $onOutput, $onHeartbeat, $executionSettings)->toArray()
                : $this->runner->run($task->project, $prompt, $onOutput, $onHeartbeat, $executionSettings);
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

            $managedProcesses = [];
            // AIOS no longer re-validates a Coder attempt with its own subprocess gate (secret
            // scan, forbidden-file check, git diff --check, re-run verification commands): a
            // successful Coder execution proceeds directly to Review, which independently judges
            // the change. A non-zero Coder exit code still fails the attempt outright.
            $validation = $execution['exit_code'] === 0
                ? ['passed' => true, 'checks' => [], 'evidence' => []]
                : ['passed' => false, 'checks' => ['codex_execution' => false]];
            if ($renewLease !== null) {
                $renewLease();
            }
            $changedFiles = $this->git->changedFilesFromBase($projectPath, $baseSha);
            $headUnchanged = $this->git->baseMatchesCurrentHead($projectPath, $baseSha);
            $validation['checks']['git_change_set'] = $changedFiles !== null;
            $validation['checks']['git_head_unchanged'] = $headUnchanged;
            $validation['evidence'] = is_array($validation['evidence'] ?? null) ? $validation['evidence'] : [];
            $validation['evidence']['git_change_set'] = [
                'name' => 'git_change_set',
                'passed' => $changedFiles !== null,
                'verification_identifier' => 'git diff --name-only',
                'exit_code' => $changedFiles === null ? 1 : 0,
                'files' => $changedFiles ?? [],
                'summary' => $changedFiles === null ? 'The changed-file set could not be determined from the attempt base.' : null,
            ];
            $validation['evidence']['git_head_unchanged'] = [
                'name' => 'git_head_unchanged',
                'passed' => $headUnchanged,
                'verification_identifier' => 'git rev-parse HEAD',
                'exit_code' => $headUnchanged ? 0 : 1,
                'summary' => $headUnchanged ? null : 'The repository HEAD changed during validation.',
            ];
            $validation['base_sha'] = $baseSha;
            $validation['candidate_changed_files'] = $changedFiles ?? [];
            $validation['repository_preflight'] = [
                'mode' => $preflight['mode'],
                'recovery_attempt_number' => $preflight['recovery_attempt']?->number,
            ];
            $validation['task_contract'] = $contractEvidence;
            $validation['managed_processes'] = $managedProcesses;
            $validationPassed = $validation['passed'] && $changedFiles !== null && $headUnchanged;
            $alreadyImplemented = $validationPassed && $changedFiles === [];
            $commitSha = $validationPassed && ! $alreadyImplemented ? $this->committer->commit($task, $changedFiles, $baseSha) : null;
            if ($renewLease !== null) {
                $renewLease();
            }
            $passed = $alreadyImplemented || ($validationPassed && $commitSha !== null);

            if ($validationPassed) {
                $validation['checks']['task_commit'] = $alreadyImplemented || $commitSha !== null;
                $validation['evidence']['task_commit'] = [
                    'name' => 'task_commit',
                    'passed' => $alreadyImplemented || $commitSha !== null,
                    'verification_identifier' => 'git commit',
                    'exit_code' => ($alreadyImplemented || $commitSha !== null) ? 0 : 1,
                    'summary' => match (true) {
                        $alreadyImplemented => 'No changes were required; the repository already satisfies this task, so nothing was committed.',
                        $commitSha === null => 'The validated task changes could not be committed.',
                        default => null,
                    },
                ];
            }

            $validation['passed'] = $passed;
            $state = $this->git->inspect($projectPath);
            $this->syncProjectGitState($task, $state);
            $candidateFiles = $changedFiles ?? [];
            $attempt->update([
                'head_sha' => $state['head_sha'],
                'commit_sha' => $commitSha,
                'status' => $passed ? 'completed' : 'failed',
                'validation_results' => $validation,
                'changed_files' => $candidateFiles,
                'finished_at' => now(),
            ]);
            if ($renewLease !== null) {
                $renewLease();
            }
            $this->audit->record('task.validated', [
                'attempt_number' => $attempt->number,
                'passed' => $passed,
                'checks' => $validation['checks'],
                'commit_sha' => $commitSha,
                'base_sha' => $baseSha,
                'changed_files' => $candidateFiles,
            ], $task->project, $task);

            if ($execution['exit_code'] === 0) {
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
            } else {
                $this->workflow->transition(
                    $task,
                    $this->retryStatus($task, $attempt),
                );
            }
        } catch (Throwable $throwable) {
            $execution = ['exit_code' => -1, 'output' => '', 'error_output' => $throwable->getMessage()];
            $this->runs->complete($run, $execution);
            $changedFiles = $this->git->changedFilesFromBase($projectPath, $baseSha) ?? [];
            $state = $this->git->inspect($projectPath);
            $this->syncProjectGitState($task, $state);
            $attempt->update([
                'head_sha' => $state['head_sha'],
                'status' => 'failed',
                'validation_results' => [
                    'passed' => false,
                    'checks' => ['execution_exception' => false],
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
        }

        return $attempt->refresh();
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
