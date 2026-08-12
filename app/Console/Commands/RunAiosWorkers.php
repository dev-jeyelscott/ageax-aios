<?php

namespace App\Console\Commands;

use App\Actions\ClaimTask;
use App\Actions\RunCoderTask;
use App\Actions\RunProjectManager;
use App\Actions\RunReviewerTask;
use App\Actions\SetProjectStatus;
use App\AgentRole;
use App\Models\Project;
use App\Models\Roadmap;
use App\ProjectStatus;
use App\Services\StaleWorkerRecovery;
use App\Services\WorkerHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aios:work {--once}')]
#[Description('Run durable AIOS workers until stopped, or one cycle with --once')]
class RunAiosWorkers extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ClaimTask $claimTask, RunCoderTask $runCoderTask, RunProjectManager $runProjectManager, RunReviewerTask $runReviewerTask, SetProjectStatus $setProjectStatus, StaleWorkerRecovery $staleWorkerRecovery, WorkerHeartbeat $heartbeat): int
    {
        do {
            foreach (Project::query()->whereIn('status', [ProjectStatus::Running, ProjectStatus::Stopping])->get() as $project) {
                if ($this->stopRequested($project, $setProjectStatus)) {
                    continue;
                }

                $staleWorkerRecovery->recover($project);
                $roadmap = Roadmap::query()->whereBelongsTo($project)->where('status', 'uploaded')->oldest()->first();
                if ($roadmap !== null) {
                    $heartbeat->beat($project, AgentRole::ProjectManager, 'working');
                    $runProjectManager->handle($roadmap);
                    $heartbeat->beat($project, AgentRole::ProjectManager, 'idle');

                    if ($this->stopRequested($project, $setProjectStatus)) {
                        continue;
                    }
                }

                foreach ([AgentRole::Coder, AgentRole::Reviewer] as $role) {
                    $heartbeat->beat($project, $role, 'idle');
                    $task = $claimTask->handle($project, $role);
                    if ($task !== null) {
                        $this->info("Claimed {$task->key} for {$role->value}.");
                        $heartbeat->beat($project, $role, 'working');
                        if ($role === AgentRole::Coder) {
                            $runCoderTask->handle($task);
                        } else {
                            $attempt = $task->attempts()->latest('number')->firstOrFail();
                            $runReviewerTask->run($task, $attempt);
                        }
                        $heartbeat->beat($project, $role, 'idle');

                        if ($this->stopRequested($project, $setProjectStatus)) {
                            continue 2;
                        }
                    }
                }
            }

            if (! $this->option('once')) {
                sleep(5);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
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
