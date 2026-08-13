<?php

namespace App\Actions;

use App\AgentRole;
use App\Exceptions\UnsafeProjectPath;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\CoderRepositoryGuard;
use App\Services\CodexCliRunner;
use App\Services\ProjectGitState;
use App\Services\TaskCommitter;
use App\Services\TaskContextCapsuleFactory;
use App\Services\TaskValidator;
use App\Services\TaskWorkflow;
use App\Services\WorkerHeartbeat;
use App\Services\WorkspacePathResolver;
use App\TaskStatus;
use Throwable;

class RunCoderTask
{
    public function __construct(private CodexCliRunner $runner, private AgentRunRecorder $runs, private TaskContextCapsuleFactory $capsules, private TaskValidator $validator, private TaskCommitter $committer, private TaskWorkflow $workflow, private WorkerHeartbeat $heartbeat, private AuditLogger $audit, private WorkspacePathResolver $paths, private CoderRepositoryGuard $repositoryGuard, private ProjectGitState $git) {}

    public function handle(Task $task): TaskAttempt
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
        $prompt = "You are the Coder role. Work only on this task. Read AGENTS.md and relevant documentation first. The roadmap constraints in the context capsule are authoritative; do not substitute another stack or add technology outside that scope. Return a concise JSON summary.\n\n".json_encode($this->capsules->make($task), JSON_THROW_ON_ERROR);
        $run = $this->runs->start($task->project, AgentRole::Coder, $prompt, $task, $attempt);

        try {
            $execution = $this->runner->run($task->project, $prompt, function (string $type, string $output) use ($run, $task): void {
                $this->runs->appendLiveOutput($run, $type, $output);
                $this->heartbeat->beat($task->project, AgentRole::Coder);
            });
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
            $validation['base_sha'] = $baseSha;
            $validation['candidate_changed_files'] = $changedFiles ?? [];
            $validation['repository_preflight'] = [
                'mode' => $preflight['mode'],
                'recovery_attempt_number' => $preflight['recovery_attempt']?->number,
            ];
            $validationPassed = $validation['passed'] && $changedFiles !== null && $headUnchanged;
            $commitSha = $validationPassed ? $this->committer->commit($task, $changedFiles, $baseSha) : null;
            $passed = $validationPassed && $commitSha !== null;

            if ($validationPassed) {
                $validation['checks']['task_commit'] = $commitSha !== null;
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
            $this->workflow->transition($task, $execution['exit_code'] === 0 ? TaskStatus::Validating : $this->retryStatus($attempt));

            if ($execution['exit_code'] === 0) {
                $this->workflow->transition($task, $passed ? TaskStatus::ReadyForReview : $this->retryStatus($attempt));
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
            $this->workflow->transition($task, $this->retryStatus($attempt));
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

