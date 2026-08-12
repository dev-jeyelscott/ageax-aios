<?php

namespace App\Actions;

use App\AgentRole;
use App\Exceptions\UnsafeProjectPath;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\CodexCliRunner;
use App\Services\GitRepositoryInspector;
use App\Services\TaskCommitter;
use App\Services\TaskContextCapsuleFactory;
use App\Services\TaskValidator;
use App\Services\TaskWorkflow;
use App\Services\WorkerHeartbeat;
use App\Services\WorkspacePathResolver;
use App\TaskStatus;
use Illuminate\Support\Facades\Process;
use Throwable;

class RunCoderTask
{
    public function __construct(private CodexCliRunner $runner, private AgentRunRecorder $runs, private TaskContextCapsuleFactory $capsules, private TaskValidator $validator, private TaskCommitter $committer, private TaskWorkflow $workflow, private WorkerHeartbeat $heartbeat, private AuditLogger $audit, private WorkspacePathResolver $paths, private GitRepositoryInspector $git) {}

    public function handle(Task $task): ?TaskAttempt
    {
        abort_unless(TaskStatus::from($task->getRawOriginal('status')) === TaskStatus::Coding, 409, 'Only claimed coding tasks may execute.');
        $task->loadMissing('project');
        try {
            $projectPath = $this->paths->assertProjectPath($task->project->path);
        } catch (UnsafeProjectPath $exception) {
            return $this->blockUnsafeProjectPath($task, $exception);
        }

        $previousAttempt = $task->attempts()->latest('number')->first();
        $isInterruptedRecovery = $previousAttempt?->status === 'interrupted';
        $preflight = $this->git->inspect($projectPath);
        $baseSha = $isInterruptedRecovery ? $previousAttempt->base_sha : $preflight['head_sha'];

        if ($baseSha === null || ! $this->preflightAllowsExecution($preflight, $isInterruptedRecovery, $baseSha)) {
            $this->blockGitPreflight($task, $preflight, $isInterruptedRecovery, $baseSha);

            return null;
        }

        $capsule = $this->capsules->make($task);
        $attempt = TaskAttempt::create([
            'task_id' => $task->id,
            'number' => $task->attempts()->max('number') + 1,
            'base_sha' => $baseSha,
            'status' => 'running',
            'validation_results' => ['git_preflight' => $this->preflightEvidence($preflight, $isInterruptedRecovery, $baseSha)],
            'started_at' => now(),
        ]);
        $recoveryInstruction = $isInterruptedRecovery
            ? "This is an interrupted-attempt recovery. Inspect and continue the existing task diff from base SHA {$baseSha}; do not discard it.\n"
            : '';
        $prompt = "You are the Coder role. Work only on this task. Read AGENTS.md and relevant documentation first. {$recoveryInstruction}Do not alter Git state; AIOS owns staging and commits. The roadmap constraints in the context capsule are authoritative; do not substitute another stack or add technology outside that scope. Return a concise JSON summary.\n\n".json_encode($capsule, JSON_THROW_ON_ERROR);
        $run = $this->runs->start($task->project, AgentRole::Coder, $prompt, $task, $attempt);

        try {
            $execution = $this->runner->run($task->project, $prompt, function (string $type, string $output) use ($run, $task): void {
                $this->runs->appendLiveOutput($run, $type, $output);
                $this->heartbeat->beat($task->project, AgentRole::Coder);
            });
            $run = $this->runs->complete($run, $execution);
            if ($execution['exit_code'] === 0) {
                $task->operatorMessages()->where('recipient_role', AgentRole::Coder)->whereNull('delivered_at')->update(['delivered_at' => now()]);
            }

            $postExecution = $this->git->inspect($projectPath);
            $headUnchanged = $postExecution['inspectable'] && $postExecution['head_sha'] === $baseSha;
            $changedFilesFromBase = $headUnchanged ? $this->git->changedFilesFromBase($projectPath, $baseSha) : null;
            $changedFiles = $changedFilesFromBase ?? [];

            if ($execution['exit_code'] === 0 && $headUnchanged && $changedFilesFromBase !== null) {
                $validation = $this->validator->validate($task, $baseSha, $changedFiles);
                $validation['checks'] = [
                    'git_head_unchanged' => true,
                    'git_diff_from_base' => true,
                    ...$validation['checks'],
                ];
            } else {
                $validation = [
                    'passed' => false,
                    'checks' => [
                        'codex_execution' => $execution['exit_code'] === 0,
                        'git_head_unchanged' => $headUnchanged,
                        'git_diff_from_base' => $changedFilesFromBase !== null,
                    ],
                ];
            }

            $validation['git_preflight'] = $this->preflightEvidence($preflight, $isInterruptedRecovery, $baseSha);
            $validation['git_post_execution'] = $postExecution;
            $validation['candidate_changed_files'] = $changedFiles;
            $commitSha = null;

            if ($validation['passed']) {
                $commitSha = $this->committer->commit($task, $changedFiles, $baseSha);
                $validation['checks']['task_commit'] = $commitSha !== null;
                $validation['passed'] = $commitSha !== null;
            }

            $passed = $validation['passed'] && $commitSha !== null;
            $attempt->update([
                'head_sha' => $this->gitHead($projectPath),
                'commit_sha' => $commitSha,
                'status' => $passed ? 'completed' : 'failed',
                'validation_results' => $validation,
                'changed_files' => $changedFiles,
                'finished_at' => now(),
            ]);
            $this->audit->record('task.validated', [
                'attempt_number' => $attempt->number,
                'passed' => $passed,
                'checks' => $validation['checks'],
                'base_sha' => $baseSha,
                'changed_files' => $changedFiles,
                'commit_sha' => $commitSha,
            ], $task->project, $task);
            $this->workflow->transition($task, $execution['exit_code'] === 0 ? TaskStatus::Validating : $this->retryStatus($attempt));

            if ($execution['exit_code'] === 0) {
                $this->workflow->transition($task, $passed ? TaskStatus::ReadyForReview : $this->retryStatus($attempt));
            }
        } catch (Throwable $throwable) {
            $execution = ['exit_code' => -1, 'output' => '', 'error_output' => $throwable->getMessage()];
            $this->runs->complete($run, $execution);
            $postExecution = $this->git->inspect($projectPath);
            $changedFiles = $postExecution['head_sha'] === $baseSha
                ? $this->git->changedFilesFromBase($projectPath, $baseSha) ?? []
                : [];
            $validation = [
                'passed' => false,
                'checks' => ['execution_exception' => false],
                'git_preflight' => $this->preflightEvidence($preflight, $isInterruptedRecovery, $baseSha),
                'git_post_execution' => $postExecution,
                'candidate_changed_files' => $changedFiles,
            ];
            $attempt->update([
                'head_sha' => $this->gitHead($projectPath),
                'status' => 'failed',
                'validation_results' => $validation,
                'changed_files' => $changedFiles,
                'finished_at' => now(),
            ]);
            $this->audit->record('task.validated', [
                'attempt_number' => $attempt->number,
                'passed' => false,
                'checks' => $validation['checks'],
                'base_sha' => $baseSha,
                'changed_files' => $changedFiles,
            ], $task->project, $task);
            $this->workflow->transition($task, $this->retryStatus($attempt));
        }

        return $attempt->refresh();
    }

    private function gitHead(string $projectPath): ?string
    {
        $result = Process::path($projectPath)->run(['git', 'rev-parse', 'HEAD']);

        return $result->successful() ? trim($result->output()) : null;
    }

    /** @param array<string, mixed> $state */
    private function preflightAllowsExecution(array $state, bool $isInterruptedRecovery, string $baseSha): bool
    {
        if (! $state['inspectable'] || $state['head_sha'] !== $baseSha) {
            return false;
        }

        return $isInterruptedRecovery || $state['clean'];
    }

    /** @param array<string, mixed> $state */
    private function blockGitPreflight(Task $task, array $state, bool $isInterruptedRecovery, ?string $baseSha): void
    {
        $task->project->update([
            'git_status' => ! $state['inspectable'] ? 'unknown' : ($state['clean'] ? 'clean' : 'dirty'),
            'git_head_sha' => $state['head_sha'],
        ]);
        $this->audit->record('task.blocked_git_preflight', [
            'mode' => $isInterruptedRecovery ? 'interrupted_recovery' : 'normal',
            'reason' => $isInterruptedRecovery
                ? 'Interrupted recovery requires repository HEAD to match the recorded clean base SHA.'
                : 'A new Coder implementation attempt requires a clean repository with a valid HEAD.',
            'action' => $isInterruptedRecovery
                ? 'Restore the repository to the recorded task base without discarding user work, then requeue the task.'
                : 'Commit, move, or otherwise resolve the existing staged and working-tree changes outside AIOS, then requeue the task.',
            'base_sha' => $baseSha,
            'repository' => $state,
        ], $task->project, $task);
        $this->workflow->transition($task, TaskStatus::Blocked);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function preflightEvidence(array $state, bool $isInterruptedRecovery, string $baseSha): array
    {
        return [
            'mode' => $isInterruptedRecovery ? 'interrupted_recovery' : 'normal',
            'base_sha' => $baseSha,
            'repository' => $state,
        ];
    }

    private function retryStatus(TaskAttempt $attempt): TaskStatus
    {
        return $attempt->number >= (int) config('aios.max_coder_attempts') ? TaskStatus::Blocked : TaskStatus::Failed;
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
}
