<?php

namespace App\Console\Commands;

use App\Actions\ClaimTask;
use App\Actions\RunCoderTask;
use App\Actions\RunProjectManager;
use App\Actions\RunReviewerTask;
use App\Actions\SetProjectStatus;
use App\AgentRole;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Roadmap;
use App\ProjectStatus;
use App\Services\WorkerHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('aios:work {--once}')]
#[Description('Run durable AIOS workers until stopped, or one cycle with --once')]
class RunAiosWorkers extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ClaimTask $claimTask, RunCoderTask $runCoderTask, RunProjectManager $runProjectManager, RunReviewerTask $runReviewerTask, SetProjectStatus $setProjectStatus, WorkerHeartbeat $heartbeat): int
    {
        $workerInstanceId = (string) Str::uuid();

        do {
            foreach (Project::query()->whereIn('status', [ProjectStatus::Running, ProjectStatus::Stopping])->get() as $project) {
                if ($this->stopRequested($project, $setProjectStatus)) {
                    continue;
                }

                // Stale worker/lease and workflow-failure recovery is owned by the Workflow
                // Recovery Engineer's five-minute scheduled scan (aios:recover-workflows), not
                // this loop; see App\Services\WorkflowRecoveryScanner/WorkflowRecoveryEngine.
                $roadmap = Roadmap::query()->whereBelongsTo($project)->whereIn('status', ['uploaded', 'failed'])->oldest()->first();
                if ($roadmap !== null) {
                    $lease = $heartbeat->acquire($project, AgentRole::ProjectManager, $workerInstanceId);
                    if ($lease !== null) {
                        try {
                            $runProjectManager->handle($roadmap, $lease);
                        } finally {
                            $heartbeat->release($lease);
                        }
                    }

                    if ($this->stopRequested($project, $setProjectStatus)) {
                        continue;
                    }
                }

                foreach ([AgentRole::Coder, AgentRole::Reviewer] as $role) {
                    if ($this->onTaskCooldown($project, $role)) {
                        continue;
                    }

                    $lease = $heartbeat->acquire($project, $role, $workerInstanceId);
                    if ($lease === null) {
                        continue;
                    }

                    $task = $claimTask->handle($project, $role);
                    if ($task !== null) {
                        $this->info("Claimed {$task->key} for {$role->value}.");
                        try {
                            if ($role === AgentRole::Coder) {
                                $runCoderTask->handle($task, $lease);
                            } else {
                                $attempt = $task->attempts()->latest('number')->firstOrFail();
                                $runReviewerTask->run($task, $attempt, $lease);
                            }
                        } finally {
                            $heartbeat->release($lease);
                        }

                        AgentWorker::query()->whereBelongsTo($project)->where('role', $role)->update(['task_completed_at' => now()]);

                        if ($this->stopRequested($project, $setProjectStatus)) {
                            continue 2;
                        }

                        continue;
                    }

                    $heartbeat->release($lease);
                }
            }

            if (! $this->option('once')) {
                sleep(5);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function onTaskCooldown(Project $project, AgentRole $role): bool
    {
        $cooldownSeconds = max(0, (int) config('aios.worker_task_cooldown_seconds'));
        if ($cooldownSeconds === 0) {
            return false;
        }

        $worker = AgentWorker::query()->whereBelongsTo($project)->where('role', $role)->first();

        return $worker?->task_completed_at !== null && $worker->task_completed_at->addSeconds($cooldownSeconds)->isFuture();
    }

    /** @phpstan-impure */
    private function stopRequested(Project $project, SetProjectStatus $setProjectStatus): bool
    {
        $freshProject = Project::query()->find($project->id);
        if ($freshProject === null || ProjectStatus::from($freshProject->getRawOriginal('status')) !== ProjectStatus::Stopping) {
            return false;
        }

        $setProjectStatus->completeStopping($freshProject);

        return true;
    }
}
