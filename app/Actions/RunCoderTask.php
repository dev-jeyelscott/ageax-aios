<?php

namespace App\Actions;

use App\AgentRole;
use App\Exceptions\UnsafeProjectPath;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\CodexCliRunner;
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
    public function __construct(private CodexCliRunner $runner, private AgentRunRecorder $runs, private TaskContextCapsuleFactory $capsules, private TaskValidator $validator, private TaskCommitter $committer, private TaskWorkflow $workflow, private WorkerHeartbeat $heartbeat, private AuditLogger $audit, private WorkspacePathResolver $paths) {}

    public function handle(Task $task): TaskAttempt
    {
        abort_unless(TaskStatus::from($task->getRawOriginal('status')) === TaskStatus::Coding, 409, 'Only claimed coding tasks may execute.');
        $task->loadMissing('project');
        try {
            $projectPath = $this->paths->assertProjectPath($task->project->path);
        } catch (UnsafeProjectPath $exception) {
            return $this->blockUnsafeProjectPath($task, $exception);
        }

        $baselineChangedFiles = $this->changedFiles($projectPath);
        $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => $task->attempts()->max('number') + 1, 'base_sha' => $this->gitHead($projectPath), 'status' => 'running', 'validation_results' => ['baseline_changed_files' => $baselineChangedFiles], 'started_at' => now()]);
        $prompt = "You are the Coder role. Work only on this task. Read AGENTS.md and relevant documentation first. The roadmap constraints in the context capsule are authoritative; do not substitute another stack or add technology outside that scope. Return a concise JSON summary.\n\n".json_encode($this->capsules->make($task), JSON_THROW_ON_ERROR);
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
            $validation = $execution['exit_code'] === 0 ? $this->validator->validate($task) : ['passed' => false, 'checks' => ['codex_execution' => false]];
            $changedFiles = array_values(array_diff($this->changedFiles($projectPath), $baselineChangedFiles));
            $validation['baseline_changed_files'] = $baselineChangedFiles;
            $validation['candidate_changed_files'] = $changedFiles;
            $commitSha = $validation['passed'] ? $this->committer->commit($task, $changedFiles) : null;
            $passed = $validation['passed'] && $commitSha !== null;
            $attempt->update(['head_sha' => $this->gitHead($projectPath), 'commit_sha' => $commitSha, 'status' => $passed ? 'completed' : 'failed', 'validation_results' => $validation, 'changed_files' => $changedFiles, 'finished_at' => now()]);
            $this->audit->record('task.validated', ['attempt_number' => $attempt->number, 'passed' => $validation['passed'], 'checks' => $validation['checks'], 'commit_sha' => $commitSha], $task->project, $task);
            $this->workflow->transition($task, $execution['exit_code'] === 0 ? TaskStatus::Validating : $this->retryStatus($attempt));

            if ($execution['exit_code'] === 0) {
                $this->workflow->transition($task, $passed ? TaskStatus::ReadyForReview : $this->retryStatus($attempt));
            }
        } catch (Throwable $throwable) {
            $execution = ['exit_code' => -1, 'output' => '', 'error_output' => $throwable->getMessage()];
            $this->runs->complete($run, $execution);
            $attempt->update(['head_sha' => $this->gitHead($projectPath), 'status' => 'failed', 'validation_results' => ['passed' => false, 'checks' => ['execution_exception' => false], 'baseline_changed_files' => $baselineChangedFiles], 'changed_files' => array_values(array_diff($this->changedFiles($projectPath), $baselineChangedFiles)), 'finished_at' => now()]);
            $this->audit->record('task.validated', ['attempt_number' => $attempt->number, 'passed' => false, 'checks' => ['execution_exception' => false]], $task->project, $task);
            $this->workflow->transition($task, $this->retryStatus($attempt));
        }

        return $attempt->refresh();
    }

    private function gitHead(string $projectPath): ?string
    {
        $result = Process::path($projectPath)->run(['git', 'rev-parse', 'HEAD']);

        return $result->successful() ? trim($result->output()) : null;
    }

    /** @return array<int, string> */
    private function changedFiles(string $projectPath): array
    {
        $status = Process::path($projectPath)->run(['git', 'status', '--porcelain']);

        if ($status->failed()) {
            return [];
        }

        return collect(preg_split('/\R/', $status->output()) ?: [])
            ->filter(fn (string $line): bool => $line !== '')
            ->map(fn (string $line): string => trim(substr($line, 3)))
            ->filter()
            ->values()
            ->all();
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
