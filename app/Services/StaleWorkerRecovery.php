<?php

namespace App\Services;

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\TaskStatus;
use Illuminate\Support\Facades\Process;
use JsonException;
use Throwable;

class StaleWorkerRecovery
{
    public function __construct(private TaskWorkflow $workflow, private AuditLogger $audit, private WorkspacePathResolver $paths) {}

    public function recover(Project $project, ?int $staleAfterSeconds = null): int
    {
        $staleAfterSeconds ??= (int) config('aios.stale_worker_after_seconds');
        $staleWorkers = $project->workers()->whereNotNull('last_heartbeat_at')->where('last_heartbeat_at', '<', now()->subSeconds($staleAfterSeconds))->get();
        foreach ($staleWorkers as $worker) {
            $role = AgentRole::from($worker->getRawOriginal('role'));
            $worker->update(['status' => 'interrupted', 'stopped_at' => now()]);
            AgentRun::query()
                ->whereBelongsTo($project)
                ->where('role', $role)
                ->where('status', AgentRunStatus::Running)
                ->update(['status' => AgentRunStatus::Interrupted, 'finished_at' => now()]);

            $statuses = match ($role) {
                AgentRole::Coder => [TaskStatus::Coding, TaskStatus::Validating],
                AgentRole::Reviewer => [TaskStatus::Reviewing],
                AgentRole::ProjectManager, AgentRole::KnowledgeArchitect => [],
            };

            if ($statuses !== []) {
                $recoveredTasks = [];
                $project->tasks()->whereIn('status', $statuses)->get()->each(function (Task $task) use ($role, $project, &$recoveredTasks): void {
                    $evidence = $this->recoveryEvidence($task);
                    $this->storeRecoveryEvidence($task, $evidence);
                    if ($role === AgentRole::Coder) {
                        $task->attempts()->where('status', 'running')->update(['status' => 'interrupted', 'finished_at' => now()]);
                        $this->workflow->transition($task, TaskStatus::Failed);
                    } else {
                        $this->workflow->recordReviewerOperationalFailure(
                            $task,
                            $task->attempts()->latest('number')->first(),
                            ['reason' => 'stale_worker', 'evidence' => $evidence],
                        );
                    }

                    $this->audit->record('task.recovered', ['role' => $role->value, 'evidence' => $evidence], $project, $task);
                    $recoveredTasks[] = ['task_key' => $task->key, 'evidence' => $evidence];
                });

                $this->audit->record('worker.recovered', ['role' => $role->value, 'tasks' => $recoveredTasks], $project);

                continue;
            }

            $this->audit->record('worker.recovered', ['role' => $role->value], $project);
        }

        return $staleWorkers->count();
    }

    /** @return array<string, mixed> */
    private function recoveryEvidence(Task $task): array
    {
        $task->loadMissing('project');
        $attempt = $task->attempts()->latest('number')->first();
        $run = $task->runs()->latest('started_at')->first();
        $evidence = [
            'base_sha' => $attempt?->base_sha,
            'previous_attempt' => $attempt?->only(['number', 'status', 'head_sha', 'commit_sha', 'validation_results', 'changed_files', 'log_path']),
            'previous_run' => $run?->only(['id', 'status', 'exit_code', 'result', 'log_path']),
        ];

        try {
            $projectPath = $this->paths->assertProjectPath($task->project->path);
        } catch (Throwable $throwable) {
            $evidence['repository_inspection_error'] = $throwable::class;

            return $evidence;
        }

        try {
            $head = Process::path($projectPath)->run(['git', 'rev-parse', 'HEAD']);
            $status = Process::path($projectPath)->run(['git', 'status', '--porcelain']);
            $diff = Process::path($projectPath)->run(['git', 'diff', '--stat', 'HEAD']);

            $evidence['current_head_sha'] = $head->successful() ? trim($head->output()) : null;
            $evidence['working_tree'] = $status->successful() ? trim($status->output()) : null;
            $evidence['diff_stat'] = $diff->successful() ? trim($diff->output()) : null;
        } catch (Throwable $throwable) {
            $evidence['repository_inspection_error'] = $throwable::class;
        }

        return $evidence;
    }

    /** @param array<string, mixed> $evidence */
    private function storeRecoveryEvidence(Task $task, array $evidence): void
    {
        $attempt = $task->attempts()->latest('number')->first();
        if (! $attempt instanceof TaskAttempt) {
            return;
        }

        try {
            $validationResults = json_decode((string) $attempt->getRawOriginal('validation_results'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $validationResults = [];
        }

        if (! is_array($validationResults)) {
            $validationResults = [];
        }

        $validationResults['recovery'] = $evidence;
        $attempt->update(['validation_results' => $validationResults]);
    }
}
