<?php

namespace App\Services;

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapAttempt;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\TaskStatus;
use App\WorkerLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class StaleWorkerRecovery
{
    public function __construct(private TaskWorkflow $workflow, private AuditLogger $audit, private WorkspacePathResolver $paths, private WorkerHeartbeat $heartbeat) {}

    public function recover(Project $project, ?int $staleAfterSeconds = null): int
    {
        $staleAfterSeconds ??= (int) config('aios.stale_worker_after_seconds');
        $recoveryInstanceId = (string) Str::uuid();
        $recovered = 0;

        foreach ([AgentRole::Coder, AgentRole::Reviewer, AgentRole::KnowledgeArchitect] as $role) {
            $lease = $this->heartbeat->takeoverExpired($project, $role, $recoveryInstanceId, $staleAfterSeconds);
            if ($lease === null) {
                continue;
            }

            try {
                $this->recoverWorker($project, $role, $lease);
                $recovered++;
            } finally {
                $this->heartbeat->release($lease, 'interrupted');
            }
        }

        return $recovered + $this->recoverOrphanedRuns($project, $staleAfterSeconds) + $this->recoverStaleRoadmaps($project, $staleAfterSeconds, $recoveryInstanceId);
    }

    /**
     * Catches executions abandoned by a crashed worker process without going through
     * takeoverExpired(): once a lease expires, the durable aios:work loop's own acquire()
     * (every few seconds) can silently reclaim-and-release that worker slot on its next idle
     * cycle, long before this five-minute scan runs, resetting last_heartbeat_at and clearing
     * lease_id. That leaves the AgentWorker row looking healthy while the specific AgentRun (and
     * its Task) the crashed process was executing stays stuck at status Running/Coding forever.
     * This scan is keyed on the run's own age and its lease no longer matching the worker's
     * current lease, so it is immune to that race.
     */
    private function recoverOrphanedRuns(Project $project, int $staleAfterSeconds): int
    {
        $recovered = 0;

        AgentRun::query()
            ->whereBelongsTo($project)
            ->whereIn('role', [AgentRole::Coder, AgentRole::Reviewer])
            ->where('status', AgentRunStatus::Running)
            ->where('started_at', '<=', now()->subSeconds($staleAfterSeconds))
            ->get()
            ->each(function (AgentRun $run) use (&$recovered): void {
                if ($this->recoverOrphanedRun($run)) {
                    $recovered++;
                }
            });

        return $recovered;
    }

    private function recoverOrphanedRun(AgentRun $run): bool
    {
        return DB::transaction(function () use ($run): bool {
            $lockedRun = AgentRun::query()->lockForUpdate()->find($run->id);
            if ($lockedRun === null || AgentRunStatus::from($lockedRun->getRawOriginal('status')) !== AgentRunStatus::Running) {
                return false;
            }

            $worker = AgentWorker::query()->whereBelongsTo($lockedRun->project)->where('role', $lockedRun->role)->first();
            if ($worker !== null && $worker->lease_id !== null && $worker->lease_id === $lockedRun->worker_lease_id) {
                // The lease that started this run is still held; it is genuinely still executing.
                return false;
            }

            $task = $lockedRun->task_id === null ? null : Task::query()->lockForUpdate()->find($lockedRun->task_id);
            $expectedStatuses = match ($lockedRun->role) {
                AgentRole::Coder => [TaskStatus::Coding, TaskStatus::Validating],
                AgentRole::Reviewer => [TaskStatus::Reviewing],
                AgentRole::KnowledgeArchitect, AgentRole::ProjectManager, AgentRole::RecoveryEngineer => [],
            };

            if ($task === null || $expectedStatuses === [] || ! in_array(TaskStatus::from($task->getRawOriginal('status')), $expectedStatuses, true)) {
                return false;
            }

            $lockedRun->update(['status' => AgentRunStatus::Interrupted, 'finished_at' => now()]);
            $evidence = $this->recoveryEvidence($task);
            $this->storeRecoveryEvidence($task, $evidence);

            if ($lockedRun->role === AgentRole::Coder) {
                $task->attempts()->where('status', 'running')->update(['status' => 'interrupted', 'finished_at' => now()]);
                $this->workflow->transition($task, TaskStatus::Failed);
            } else {
                $this->workflow->recordReviewerOperationalFailure($task, $task->attempts()->latest('number')->first(), ['reason' => 'orphaned_agent_run', 'evidence' => $evidence]);
            }

            $this->audit->record('task.recovered', ['role' => $lockedRun->role->value, 'reason' => 'orphaned_agent_run', 'agent_run_id' => $lockedRun->id, 'evidence' => $evidence], $lockedRun->project, $task);

            return true;
        }, attempts: 3);
    }

    private function recoverWorker(Project $project, AgentRole $role, WorkerLease $lease): void
    {
        $this->interruptRuns($project, $role, $lease);
        $statuses = match ($role) {
            AgentRole::Coder => [TaskStatus::Coding, TaskStatus::Validating],
            AgentRole::Reviewer => [TaskStatus::Reviewing],
            AgentRole::KnowledgeArchitect, AgentRole::ProjectManager, AgentRole::RecoveryEngineer => [],
        };

        if ($statuses === []) {
            $this->audit->record('worker.recovered', ['role' => $role->value, 'worker_instance_id' => $lease->workerInstanceId, 'lease_id' => $lease->leaseId], $project);

            return;
        }

        $recoveredTasks = [];
        $project->tasks()->whereIn('status', $statuses)->get()->each(function (Task $task) use ($role, $project, &$recoveredTasks): void {
            $evidence = $this->recoveryEvidence($task);
            $this->storeRecoveryEvidence($task, $evidence);
            if ($role === AgentRole::Coder) {
                $task->attempts()->where('status', 'running')->update(['status' => 'interrupted', 'finished_at' => now()]);
                $this->workflow->transition($task, TaskStatus::Failed);
            } else {
                $this->workflow->recordReviewerOperationalFailure($task, $task->attempts()->latest('number')->first(), ['reason' => 'expired_worker_lease', 'evidence' => $evidence]);
            }

            $this->audit->record('task.recovered', ['role' => $role->value, 'evidence' => $evidence], $project, $task);
            $recoveredTasks[] = ['task_key' => $task->key, 'evidence' => $evidence];
        });

        $this->audit->record('worker.recovered', ['role' => $role->value, 'worker_instance_id' => $lease->workerInstanceId, 'lease_id' => $lease->leaseId, 'tasks' => $recoveredTasks], $project);
    }

    private function recoverStaleRoadmaps(Project $project, int $staleAfterSeconds, string $recoveryInstanceId): int
    {
        $attempts = RoadmapAttempt::query()
            ->whereHas('roadmap', fn ($query) => $query->whereBelongsTo($project)->where('status', 'processing'))
            ->whereIn('status', ['claimed', 'running'])
            ->where('claimed_at', '<', now()->subSeconds($staleAfterSeconds))
            ->get();
        if ($attempts->isEmpty()) {
            return 0;
        }

        $worker = $project->workers()->where('role', AgentRole::ProjectManager)->first();
        $lease = $worker === null ? null : $this->heartbeat->takeoverExpired($project, AgentRole::ProjectManager, $recoveryInstanceId, $staleAfterSeconds);
        if ($worker !== null && $lease === null) {
            return 0;
        }

        try {
            foreach ($attempts as $attempt) {
                DB::transaction(function () use ($attempt, $project, $lease, $staleAfterSeconds): void {
                    $lockedAttempt = RoadmapAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                    $roadmap = Roadmap::query()->lockForUpdate()->findOrFail($lockedAttempt->roadmap_id);
                    if ($roadmap->getRawOriginal('status') !== 'processing' || ! in_array($lockedAttempt->getRawOriginal('status'), ['claimed', 'running'], true) || RoadmapAttempt::query()->whereKey($lockedAttempt)->where('claimed_at', '>=', now()->subSeconds($staleAfterSeconds))->exists()) {
                        return;
                    }

                    if ($lockedAttempt->agent_run_id !== null) {
                        $run = AgentRun::query()->lockForUpdate()->find($lockedAttempt->agent_run_id);
                        if ($run !== null && $run->getRawOriginal('status') === AgentRunStatus::Running->value && ($lease === null || $lease->previousLeaseId === null || $run->worker_lease_id === $lease->previousLeaseId)) {
                            $run->update(['status' => AgentRunStatus::Interrupted, 'finished_at' => now()]);
                        }
                    }

                    $lockedAttempt->update(['status' => 'interrupted', 'finished_at' => now()]);
                    $roadmap->update(['status' => 'failed']);
                    $this->audit->record('roadmap.processing_interrupted', ['roadmap_id' => $roadmap->id, 'roadmap_attempt_id' => $lockedAttempt->id, 'agent_run_id' => $lockedAttempt->agent_run_id, 'worker_instance_id' => $lease?->workerInstanceId, 'lease_id' => $lease?->leaseId], $project);
                    $this->audit->record('roadmap.retry_scheduled', ['roadmap_id' => $roadmap->id, 'roadmap_attempt_id' => $lockedAttempt->id], $project);
                }, attempts: 3);
            }
        } finally {
            if ($lease !== null) {
                $this->heartbeat->release($lease, 'interrupted');
            }
        }

        return $attempts->count() + ($lease === null ? 0 : 1);
    }

    private function interruptRuns(Project $project, AgentRole $role, WorkerLease $lease): void
    {
        AgentRun::query()->whereBelongsTo($project)->where('role', $role)->where('status', AgentRunStatus::Running)
            ->where(function ($query) use ($lease): void {
                if ($lease->previousLeaseId !== null) {
                    $query->where('worker_lease_id', $lease->previousLeaseId);

                    return;
                }

                $query->whereNull('worker_lease_id');
            })
            ->update(['status' => AgentRunStatus::Interrupted, 'finished_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function recoveryEvidence(Task $task): array
    {
        $task->loadMissing('project');
        $attempt = $task->attempts()->latest('number')->first();
        $run = $task->runs()->latest('started_at')->first();
        $evidence = ['base_sha' => $attempt?->base_sha, 'previous_attempt' => $attempt?->only(['number', 'status', 'head_sha', 'commit_sha', 'validation_results', 'changed_files', 'log_path']), 'previous_run' => $run?->only(['id', 'status', 'exit_code', 'result', 'log_path', 'worker_instance_id', 'worker_lease_id'])];

        try {
            $projectPath = $this->paths->assertProjectPath($task->project->path);
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
        $validationResults = is_array($validationResults) ? $validationResults : [];
        $validationResults['recovery'] = $evidence;
        $attempt->update(['validation_results' => $validationResults]);
    }
}
