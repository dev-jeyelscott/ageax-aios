<?php

namespace App\Actions;

use App\AgentRole;
use App\Exceptions\AgentNotBoundToRole;
use App\Exceptions\UnsafeProjectPath;
use App\Models\Agent;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Services\AgentContextAssembler;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResolver;
use App\Services\AgentResolver;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\CoderRepositoryGuard;
use App\Services\CodexCliRunner;
use App\Services\NoProgressRetryGuard;
use App\Services\ProjectGitState;
use App\Services\TaskCommitter;
use App\Services\TaskContextCapsuleFactory;
use App\Services\TaskValidator;
use App\Services\TaskWorkflow;
use App\Services\WorkerHeartbeat;
use App\Services\WorkspacePathResolver;
use App\TaskStatus;
use App\WorkerLease;
use LogicException;
use Throwable;

class RunCoderTask
{
    public function __construct(private CodexCliRunner $runner, private AgentResolver $agents, private AgentHarnessResolver $harnesses, private AgentContextAssembler $contextAssembler, private AgentRunRecorder $runs, private TaskContextCapsuleFactory $capsules, private TaskValidator $validator, private TaskCommitter $committer, private TaskWorkflow $workflow, private NoProgressRetryGuard $noProgress, private WorkerHeartbeat $heartbeat, private AuditLogger $audit, private WorkspacePathResolver $paths, private CoderRepositoryGuard $repositoryGuard, private ProjectGitState $git) {}

    public function handle(Task $task, ?WorkerLease $lease = null): TaskAttempt
    {
        abort_unless(TaskStatus::from($task->getRawOriginal('status')) === TaskStatus::Coding, 409, 'Only claimed coding tasks may execute.');
        $task->loadMissing('project');

        try {
            $projectPath = $this->paths->assertProjectPath($task->project->path);
        } catch (UnsafeProjectPath $exception) {
            return $this->blockUnsafeProjectPath($task, $exception);
        }

        $preflight = $this->repositoryGuard->inspect($task);

        if (! $preflight['allowed'] || $preflight['base_sha'] === null) {
            return $this->blockRepositoryPreflight($task, $preflight);
        }

        $baseSha = $preflight['base_sha'];

        // AIOS resolves the Agent bound to this workflow role for the deterministic context
        // snapshot and harness dispatch (P2-011/P2-012). Projects provisioned without a bound
        // Agent fall back to the legacy default execution path; such runs remain legacy runs.
        // A binding that exists but is disabled, missing, or otherwise misconfigured blocks
        // processing with actionable audit evidence instead of silently falling back (P2-015).
        try {
            [$agent, $harness] = $this->resolveAgent($task->project, AgentRole::Coder);
        } catch (LogicException $exception) {
            return $this->blockMisconfiguredAgent($task, $exception);
        }

        $context = $this->capsules->make($task);
        $assembled = $agent === null ? null : $this->contextAssembler->assemble($agent, AgentRole::Coder, $context);
        $prompt = "You are the Coder role. Work only on this task. Read AGENTS.md and relevant documentation first. The roadmap constraints in the context capsule are authoritative; do not substitute another stack or add technology outside that scope. Return a concise JSON summary.\n\n".json_encode($assembled?->toArray() ?? $context, JSON_THROW_ON_ERROR);
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
            ],
            'started_at' => now(),
        ]);
        $run = $this->runs->start($task->project, AgentRole::Coder, $prompt, $task, $attempt, $lease, $context['retrieval_manifest'], $agent, $assembled);

        try {
            $onOutput = function (string $type, string $output) use ($run, $task, $lease): void {
                $this->runs->appendLiveOutput($run, $type, $output);
                if ($lease === null) {
                    $this->heartbeat->beat($task->project, AgentRole::Coder);
                }
            };
            $onHeartbeat = $lease === null ? null : fn (): bool => $this->heartbeat->renew($lease);
            $execution = $harness !== null && $agent !== null
                ? $harness->execute($task->project, $agent, $prompt, $onOutput, $onHeartbeat)->toArray()
                : $this->runner->run($task->project, $prompt, $onOutput, $onHeartbeat);
            $this->runs->complete($run, $execution);

            if ($execution['exit_code'] === 0) {
                $task->operatorMessages()->where('recipient_role', AgentRole::Coder)->whereNull('delivered_at')->update(['delivered_at' => now()]);
            }

            $validation = $execution['exit_code'] === 0
                ? $this->validator->validate($task)
                : ['passed' => false, 'checks' => ['codex_execution' => false]];
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
            $validationPassed = $validation['passed'] && $changedFiles !== null && $headUnchanged;
            // A validated attempt with zero changed files means the repository already satisfies
            // this task (e.g. a prior attempt or unrelated task already implemented it) rather
            // than a failed commit; nothing to commit is expected, not an error.
            $alreadyImplemented = $validationPassed && $changedFiles === [];
            $commitSha = $validationPassed && ! $alreadyImplemented ? $this->committer->commit($task, $changedFiles, $baseSha) : null;
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
            $this->audit->record('task.validated', [
                'attempt_number' => $attempt->number,
                'passed' => $passed,
                'checks' => $validation['checks'],
                'commit_sha' => $commitSha,
                'base_sha' => $baseSha,
                'changed_files' => $candidateFiles,
            ], $task->project, $task);
            $this->workflow->transition($task, $execution['exit_code'] === 0 ? TaskStatus::Validating : $this->retryStatus($task, $attempt));

            if ($execution['exit_code'] === 0) {
                $this->workflow->transition($task, $passed ? TaskStatus::ReadyForReview : $this->retryStatus($task, $attempt));
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

    private function retryStatus(Task $task, TaskAttempt $attempt): TaskStatus
    {
        $noProgress = $this->noProgress->coderFailure($task, $attempt->refresh());
        $validationResults = is_array($attempt->validation_results) ? $attempt->validation_results : [];
        $attempt->update(['validation_results' => [...$validationResults, 'no_progress' => $noProgress]]);

        $limit = max(1, (int) config('aios.max_coder_attempts'));
        $lastRecovery = $task->auditEvents()
            ->whereIn('event_type', ['task.requeued', 'task.coder_retry_exhausted'])
            ->latest('occurred_at')
            ->first();
        $attemptsSinceRecovery = $task->attempts()
            ->when($lastRecovery !== null, fn ($query) => $query->where('created_at', '>=', $lastRecovery->occurred_at))
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

    /** @return array{0: ?Agent, 1: ?AgentHarness} */
    private function resolveAgent(Project $project, AgentRole $role): array
    {
        try {
            $agent = $this->agents->forRole($project, $role);
        } catch (AgentNotBoundToRole) {
            return [null, null];
        }

        return [$agent, $this->harnesses->resolve($agent)];
    }

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
